<?php
namespace CH;

class CourseHubBuilder
{
    private CartridgeParser  $parser;
    private ContentConverter $converter;

    public array $warnings = [];
    public array $errors   = [];
    public int   $pageCount   = 0;
    public int   $droppedCount = 0;
    public array $droppedByType = [];

    private array  $files = [];           // zipPath => string content
    private array  $attachmentFiles = []; // zipPath => local file path
    private bool   $skipFiles;
    private bool   $skipImageDownload;
    private bool   $stripTitleNumbering;
    private bool   $includeEssentials;
    private bool   $includeResources;
    private bool   $includeSyllabus;
    private bool   $portableMarkdown;
    private string $courseBase;           // e.g. "pages/my-course-2025", or "pages" for Open Course Hub
    private string $destination;          // 'helios', 'open-course-hub' or 'open-multicourse-hub'
    private string $modulesFolder;        // "30.modules", or "07.modules" for Open Course Hub
    private array $pendingImages   = []; // ['pageFolder', 'filename', 'localPath'|null, 'url'|null]
    private array $imageData       = []; // zipPath => binary string (populated by downloadPendingImages)
    private int   $imageFailures   = 0;
    private int   $externalUrlCount = 0;
    private int   $attachmentCount  = 0;
    private array $slugToRoute      = []; // Canvas wiki-page slug => Grav route, built as pages are created;
                                           // used to resolve $WIKI_REFERENCE$ links at the end of build()
    private array $usedSlugs        = []; // parent folder => the page slugs already used in it (see uniqueSlug())

    private static array $introKeywords = ['intro', 'introduction', 'welcome', 'how to use', 'getting started', 'about'];

    public function __construct(
        CartridgeParser $parser,
        bool $skipFiles = true,
        bool $skipImageDownload = false,
        bool $stripTitleNumbering = false,
        bool $includeEssentials = true,
        bool $includeResources = true,
        bool $includeSyllabus = true,
        bool $portableMarkdown = false,
        string $destination = 'helios'
    ) {
        $this->parser              = $parser;
        $this->converter           = new ContentConverter($parser->dir, $skipImageDownload, $skipFiles, $portableMarkdown);
        $this->skipFiles           = $skipFiles;
        $this->skipImageDownload   = $skipImageDownload;
        $this->stripTitleNumbering = $stripTitleNumbering;
        $this->includeEssentials   = $includeEssentials;
        $this->includeResources    = $includeResources;
        $this->includeSyllabus     = $includeSyllabus;
        $this->portableMarkdown    = $portableMarkdown;

        // Where the course will go. Standard Markdown is for any Grav site, so it always keeps
        // the course in its own folder, as for Helios
        $this->destination = 'helios';
        if ($destination === 'open-course-hub' || $destination === 'open-multicourse-hub') {
            $this->destination = $destination;
        }
        if ($portableMarkdown) {
            $this->destination = 'helios';
        }

        // An Open Course Hub site keeps its own weekly home page, schedule and sidebar, so only the
        // modules are exported, as a Modules folder to add to the site: no course folder (so page
        // addresses have none either), and the same folder name as the skeleton's Modules sample
        if ($this->destination === 'open-course-hub') {
            $this->courseBase    = 'pages';
            $this->modulesFolder = '07.modules';
        } else {
            $this->courseBase    = 'pages/' . $parser->courseSlug;
            $this->modulesFolder = '30.modules';
        }
    }

    // Applies the opt-in "clean up numbered titles" setting. See
    // Helpers::stripLeadingNumbering() for what gets stripped and why.
    private function cleanTitle(string $title): string
    {
        return $this->stripTitleNumbering ? Helpers::stripLeadingNumbering($title) : $title;
    }

    // Exposes the in-memory Markdown files build() already assembled — used by the
    // preview endpoint, which needs page content without writing an actual zip.
    public function getFiles(): array
    {
        return $this->files;
    }

    // zipPath => binary image data, already downloaded by build() regardless of the
    // "skip image download" setting for locally-bundled cartridge images (only remote-URL
    // images are affected by that flag). Lets the preview embed real images instead of
    // always showing a placeholder.
    public function getImageData(): array
    {
        return $this->imageData;
    }

    public function build(): string
    {
        $this->warnings = array_merge($this->warnings, $this->parser->warnings);

        // Pre-flight: count remote image URLs across all wiki pages.
        // If there are too many for a web request, auto-switch to remote URL mode
        // so the converter never attempts downloads (avoids PHP timeout hangs).
        if (!$this->skipImageDownload) {
            $remoteCount = 0;
            foreach ($this->parser->wikiPages as $html) {
                preg_match_all('/<img[^>]+src=["\']https?:\/\//i', $html, $m);
                $remoteCount += count($m[0]);
            }
            if ($remoteCount > 100) {
                $this->skipImageDownload = true;
                $this->converter         = new ContentConverter($this->parser->dir, true, $this->skipFiles, $this->portableMarkdown);
                $this->warnings[]        = "Course has $remoteCount remote images — too many to download reliably in a web request. Images will use remote URLs instead. Enable \"Skip image download\" to suppress this message.";
            }
        }

        $this->buildCourseMd();
        $this->buildHeaderImagePage();
        $this->buildModules();
        $hasSyllabusContent = $this->parser->syllabusHtml && strlen(strip_tags($this->parser->syllabusHtml)) >= 10;
        if ($this->destination === 'open-course-hub') {
            // Only the modules are exported, so there's no Essentials or Resources page; any
            // syllabus content goes in its own folder, to copy into the site's Syllabus page
            if ($hasSyllabusContent) $this->buildSyllabusForCopying();
        } else {
            if ($this->includeEssentials) $this->buildEssentials();
            if ($this->includeResources)  $this->buildResources();
            // Always build syllabus when source content exists; flag only suppresses the stub.
            if ($this->includeSyllabus || $hasSyllabusContent) $this->buildSyllabus();
        }

        $this->downloadPendingImages();
        $this->dropFailedImageReferences();
        $this->resolveWikiRefLinks();
        $this->buildConversionNotes();

        $this->warnings = array_merge($this->warnings, $this->converter->warnings);

        return $this->createZip();
    }

    // ── Page builders ─────────────────────────────────────────────────────────

    private function buildCourseMd(): void
    {
        // Purely Course Hub homepage-card config (no template exists for it) — has no
        // meaning without the plugin, so Standard Markdown mode omits it entirely.
        if ($this->portableMarkdown) return;

        // An Open Course Hub site is the course itself, so it has no course file
        if ($this->destination === 'open-course-hub') return;

        // An Open MultiCourse Hub course uses subsite.md: its title and description on the
        // course list, and the page its address opens (Home). visible: false keeps the course
        // itself out of the site's menu
        if ($this->destination === 'open-multicourse-hub') {
            $title = $this->parser->courseTitle;
            if (!$title) {
                $title = Helpers::titleFromSlug($this->parser->courseSlug);
            }
            $yaml = "---\ntitle: '" . Helpers::yamlEscape($title) . "'\npublished: true\nvisible: false\n"
                  . "subsite_list_description: ''\nsubsite_home: home\n---\n\n"
                  . "Use the Admin Panel Editor (or page frontmatter) to set this course's description on the course list.\n";
            $this->addFile("$this->courseBase/subsite.md", $yaml);
            return;
        }

        $desc = $this->parser->courseTitle ? "\ndescription: '" . Helpers::yamlEscape($this->parser->courseTitle) . "'" : '';
        $body = "Use the Admin Panel Editor (or page frontmatter) to set the icon, description, and published status of this course's card on the Courses homepage.";
        $yaml = "---\npublished: true\nroutable: false$desc\n---\n\n$body\n";
        $this->addFile("$this->courseBase/course.md", $yaml);
    }

    // Bootstrap4 Open Matter shows the image in a headerimage folder above the menu (and, in an
    // Open MultiCourse Hub, on the course's card), so the course image goes there. The image
    // file itself is added in createZip()
    private function buildHeaderImagePage(): void
    {
        // Helios uses course_image instead, and an Open Course Hub site already has its own
        // headerimage page (only the image file is exported, to swap in; see createZip())
        if ($this->destination !== 'open-multicourse-hub') return;
        if (!$this->parser->courseImagePath) return;
        if (!file_exists($this->parser->courseImagePath)) return;

        $yaml = "---\ntitle: 'Header Image'\nvisible: false\nroutable: false\n---\n"
              . "File to be used for the header image above the menubar.\n";
        $this->addFile("$this->courseBase/headerimage/default.md", $yaml);
    }

    // The folder the course image goes in: headerimage for Bootstrap4 Open Matter (pages/headerimage
    // for an Open Course Hub site, or the course's own headerimage folder in an Open MultiCourse Hub),
    // or course_image for Helios
    private function courseImageFolder(): string
    {
        if ($this->destination === 'helios') {
            return "$this->courseBase/course_image";
        }
        return "$this->courseBase/headerimage";
    }

    private function buildModules(): void
    {
        $p       = $this->parser;
        $modules = $p->modules;

        // Open Course Hub: every module (including an intro or "Welcome" module, which would
        // otherwise become the course's Home page) goes in the Modules folder, as a card on the
        // Modules page, as in the skeleton's Modules sample
        if ($this->destination === 'open-course-hub') {
            $this->buildModuleListing();
            $modN = 1;
            foreach ($modules as $mod) {
                if ($this->isBeltEarned($mod)) {
                    continue;
                }
                $this->buildModuleChapter($mod, $modN);
                $modN++;
            }
            return;
        }

        if (empty($modules)) {
            $this->buildHomePage(null);
            return;
        }

        // Detect intro module (first module with intro-like title and no child pages needed)
        $introModule = null;
        $contentModules = $modules;

        $firstTitle = strtolower($modules[0]['title'] ?? '');
        foreach (self::$introKeywords as $kw) {
            if (strpos($firstTitle, $kw) !== false) {
                $introModule    = $modules[0];
                $contentModules = array_slice($modules, 1);
                break;
            }
        }

        // Build the home page (always created — uses the intro module's content when
        // one was detected, otherwise just the conversion notice)
        $this->buildHomePage($introModule);

        // Filter out belt-earned checkpoint modules.
        $realModules = array_values(array_filter($contentModules, fn($m) =>
            !$this->isBeltEarned($m)
        ));

        if (count($realModules) === 1) {
            // Single module: flatten child pages directly under 30.modules/ to avoid an
            // extra navigation layer (Modules → Chapter → pages becomes Modules → pages)
            $this->buildSingleModuleFlat($realModules[0]);
        } else {
            $this->buildModuleListing();
            $modN = 1;
            foreach ($realModules as $mod) {
                $this->buildModuleChapter($mod, $modN);
                $modN++;
            }
        }
    }

    // Home only ever holds the intro module's own landing content – any other items in
    // that module (indent-1 children, or any ExternalUrl/Attachment) become their own
    // numbered child pages via buildChildPage(), the same split buildModuleChapter() applies
    // to every other module. Home previously flattened the whole module onto one page
    // regardless of indent, which could pack a dozen unrelated pages worth of content onto
    // the site's single landing page instead of giving each its own place in the nav tree.
    private function buildHomePage(?array $mod): void
    {
        $base = "$this->courseBase/10.home";

        if (!$mod) {
            $fm = $this->pageFrontmatter('Home', [], true);
            $this->addFile("$base/course-page.md", $fm . $this->conversionNotice());
            return;
        }

        [$landingItem, $childItems] = $this->splitItems($mod);

        $landingHtml = $landingItem ? $this->getWikiHtml($landingItem) : '';
        $landingBody = $landingHtml ? $this->convertAndCollectImages($landingHtml, $base) : '';
        if ($landingItem) $this->registerRoute($landingItem['slug'], "$base/course-page.md");

        $title = Helpers::yamlEscape($this->cleanTitle($mod['title']));
        $fm    = $this->pageFrontmatter($title, [], true);
        $this->addFile("$base/course-page.md", $fm . $this->conversionNotice() . trim($landingBody) . "\n");
        $this->pageCount++;

        $childN = 1;
        foreach ($childItems as $item) {
            $this->buildChildPage($base, $item, $childN, $mod['title']);
            $childN++;
        }

        $this->trackDropped($mod);
    }

    // A brief, unmissable notice on the course home page — so anyone who later browses
    // the converted site (not just whoever ran the conversion) has context for why
    // formatting or links might look rough. Worded for two audiences at once: a demo
    // visitor reads it as a neutral fact ("auto-converted, not yet polished"), while the
    // course author gets an explicit next step. Unlike a curated book import, LMS course
    // exports vary too widely in authoring quality for a clean automatic conversion.
    private function conversionNotice(): string
    {
        return "> [!IMPORTANT]\n"
             . "> This course was automatically converted from a Common Cartridge (LMS) export "
             . "and has not yet been adjusted for optimal results. If you're the course author, "
             . "see `conversion-notes.txt` in this download for what was excluded or flagged, then "
             . "remove this notice once you're satisfied with the content.\n\n";
    }

    private function buildModuleListing(): void
    {
        $base = "$this->courseBase/$this->modulesFolder";
        $fm   = $this->portableMarkdown
            ? "---\ntitle: Modules\n---\n"
            : "---\ntitle: Modules\npublished: true\ndescription: 'Below are the modules available for this course.'\ntaxonomy:\n    category: docs\nnavigation:\n    toc_position: hidden\n---\n";
        // an Open Course Hub export has no Home page, so the conversion notice goes on the Modules page
        if ($this->destination === 'open-course-hub') {
            $fm .= "\n" . $this->conversionNotice();
        }
        $this->addFile("$base/module.md", $fm);
    }

    private function buildSingleModuleFlat(array $mod): void
    {
        $base = "$this->courseBase/$this->modulesFolder";

        // Use the module's landing WikiPage content in course-page.md itself
        $items       = $mod['items'];
        $landingItem = null;
        $childItems  = [];

        [$landingItem, $childItems] = $this->splitItems($mod);

        $landingHtml = $landingItem ? $this->getWikiHtml($landingItem) : '';
        $landingBody = $landingHtml ? $this->convertAndCollectImages($landingHtml, $base) : '';
        $landingBody .= $this->ltiWarning($items);
        if ($landingItem) $this->registerRoute($landingItem['slug'], "$base/course-page.md");

        $title = Helpers::yamlEscape($this->cleanTitle($mod['title']));
        $fm    = $this->pageFrontmatter($title, [], true);
        $this->addFile("$base/course-page.md", $fm . trim($landingBody) . "\n");
        $this->pageCount++;

        $childN = 1;
        foreach ($childItems as $item) {
            $this->buildChildPage($base, $item, $childN, $mod['title']);
            $childN++;
        }

        $this->trackDropped($mod);
    }

    private function buildModuleChapter(array $mod, int $n): void
    {
        $modSlug  = Helpers::slugify($this->cleanTitle($mod['title']));
        $modSlug  = $this->uniqueSlug("$this->courseBase/$this->modulesFolder", $modSlug);
        $modFolder = Helpers::numberedFolder($n, $modSlug);
        $base      = "$this->courseBase/$this->modulesFolder/$modFolder";

        $items = $mod['items'];
        [$landingItem, $childItems] = $this->splitItems($mod);

        // Chapter landing page
        $landingHtml = $landingItem ? $this->getWikiHtml($landingItem) : '';
        $landingBody = $landingHtml ? $this->convertAndCollectImages($landingHtml, $base) : '';
        $landingBody .= $this->ltiWarning($items);
        if ($landingItem) $this->registerRoute($landingItem['slug'], "$base/course-page.md");

        // If the landing is blank and there is exactly one child, promote its content
        // to the landing so the module doesn't show an empty page with a single card below it
        if (trim($landingBody) === '' && count($childItems) === 1) {
            $only = $childItems[0];
            if ($only['type'] === 'WikiPage') {
                $childHtml   = $this->getWikiHtml($only);
                $landingBody = $childHtml ? $this->convertAndCollectImages($childHtml, $base) : '';
                // the module's card description then comes from this page too
                $landingHtml = $childHtml;
                $this->registerRoute($only['slug'], "$base/course-page.md");
                $childItems  = [];
            } elseif ($only['type'] === 'ExternalUrl') {
                $url = $only['url'] ?? '';
                $landingBody = $url ? "[{$this->cleanTitle($only['title'])}]($url)\n" : '';
                $childItems  = [];
                $this->externalUrlCount++;
            }
        }

        if (trim($landingBody) === '') {
            if (count($childItems) >= 1) {
                // Has convertible child pages but no landing content — suggest an intro.
                $landingBody = "> [!TIP]\n> Add an introduction or overview for this module here.\n";
            } else {
                // All items were Canvas-specific (discussions, quizzes, etc.) — nothing to convert.
                $landingBody = "> [!NOTE]\n> This module contains Canvas discussions, quizzes, or other activities that could not be exported. Access them in the original course.\n";
            }
        }

        $title    = Helpers::yamlEscape($this->cleanTitle($mod['title']));
        $desc     = $landingBody ? Helpers::yamlEscape($this->cardDescription($landingHtml ?: '')) : '';
        $extras   = $desc ? ["description: '$desc'"] : [];
        $fm       = $this->pageFrontmatter($title, $extras);
        $this->addFile("$base/course-page.md", $fm . trim($landingBody) . "\n");
        $this->pageCount++;

        // Child pages
        $childN = 1;
        foreach ($childItems as $item) {
            $this->buildChildPage($base, $item, $childN, $mod['title']);
            $childN++;
        }

        $this->trackDropped($mod);
    }

    // A module's card description: the page's first real paragraph (<p>) in full, so it never ends
    // mid-sentence; the course hub's own setting decides how many lines show (2, 3, or no limit).
    // A "real" paragraph has at least 60 characters and doesn't end with a colon, which skips
    // labels and lead-ins such as "Main author: …", "Link" or "The blue belt focuses on:".
    // Only paragraph text is used, as everything else would be joined into run-on words: the page
    // title from the document's <head>, headings, and lists such as a tab menu ("Canvas Overview
    // Global Navigation Dashboard Sidebar…"). A page with no paragraphs, such as one that's just a
    // table, falls back to the start of all its text instead
    private function cardDescription(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Step 1: read the page (the meta tag keeps accented letters and emoji intact)
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true); // Canvas HTML often isn't perfectly formed; ignore warnings
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        // Step 2: go through the paragraphs, returning the first real one, and remembering the
        // first one with any text in case there's no real one (toPlainText also turns a
        // non-breaking space into a normal one, so a paragraph holding only &nbsp; counts as empty)
        $minimumLength = 60;
        $firstParagraph = '';
        foreach ($dom->getElementsByTagName('p') as $paragraph) {
            $text = $this->converter->toPlainText($paragraph->textContent);
            if ($text === '') {
                continue;
            }
            if ($firstParagraph === '') {
                $firstParagraph = $text;
            }
            $isLongEnough = mb_strlen($text) >= $minimumLength;
            $endsWithColon = str_ends_with($text, ':');
            if ($isLongEnough && !$endsWithColon) {
                return $text;
            }
        }
        if ($firstParagraph !== '') {
            return $firstParagraph;
        }

        // Step 3: no paragraphs at all, so use the start of all the page's text (without the <head> title)
        $withoutHead = preg_replace('#<head[^>]*>.*?</head>#is', ' ', $html);
        return Helpers::shortDescription($this->converter->toPlainText($withoutHead));
    }

    private function buildChildPage(string $moduleBase, array $item, int $n, string $modTitle): void
    {
        $cleanedTitle = $this->cleanTitle($item['title']);

        // Strip file extension from attachment titles (e.g. "Report.pdf" → "Report") for clean slugs
        $titleForSlug = $item['type'] === 'Attachment'
            ? preg_replace('/\.[a-zA-Z0-9]{2,5}$/', '', $cleanedTitle)
            : $cleanedTitle;
        $slug      = Helpers::slugify($titleForSlug);
        $slug      = $this->uniqueSlug($moduleBase, $slug);
        $folder    = Helpers::numberedFolder($n, $slug);
        $path      = "$moduleBase/$folder/course-page.md";
        $title   = Helpers::yamlEscape($cleanedTitle);
        $extras  = $item['group'] ? ["group: '" . Helpers::yamlEscape($item['group']) . "'"] : [];
        $fm      = $this->pageFrontmatter($title, $extras);

        if ($item['type'] === 'WikiPage') {
            $this->registerRoute($item['slug'], $path);
            $html  = $this->getWikiHtml($item);
            $pageFolder = dirname($path); // e.g. pages/30.modules/01.mod/01.child
            $body  = $html ? $this->convertAndCollectImages($html, $pageFolder) : '';
        } elseif ($item['type'] === 'ExternalUrl') {
            $url  = $item['url'] ?? '';
            $body = $url ? $this->externalUrlBody($url, $cleanedTitle) : '';
            $this->externalUrlCount++;
        } elseif ($item['type'] === 'Attachment') {
            $filePath = $item['filePath'] ?? '';
            if ($filePath && !$this->skipFiles) {
                // Grav reads "name:value" in an address as a parameter (as in /chromeless:true),
                // so a file name with a colon could never be opened; a hyphen is used instead
                $filename = str_replace(':', '-', basename($filePath));

                // Step 1: the folder the files go in. Only the Modules folder is copied into an
                // Open Course Hub site, so there the files go inside it (pages/07.modules/files/);
                // otherwise they're in the course folder (pages/my-course/files/)
                if ($this->destination === 'open-course-hub') {
                    $filesParent = "$this->courseBase/$this->modulesFolder";
                } else {
                    $filesParent = $this->courseBase;
                }
                $zipPath = "$filesParent/files/$filename";

                // Step 2: the link, going up one level for each folder between the files' folder
                // and this page: 3 for a page in a module (30.modules/01.mod/01.page), but 2 for a
                // page under Home (10.home/01.page) or in an Open Course Hub module (01.mod/01.page)
                $pageFolder   = dirname($path);
                $foldersBelow = explode('/', substr($pageFolder, strlen($filesParent) + 1));
                $linkPath     = str_repeat('../', count($foldersBelow)) . 'files/';

                // Step 3: spaces and other characters in a file name would break the Markdown
                // link, so they're encoded (a space becomes %20)
                $linkPath .= rawurlencode($filename);

                $this->attachmentFiles[$zipPath] = $filePath;
                $body = "[$cleanedTitle]($linkPath)\n";
            } else {
                $body = "> **$cleanedTitle** — attached file not included (see conversion-notes.txt)\n";
            }
            $this->attachmentCount++;
        } else {
            $body = '';
        }

        $this->addFile($path, $fm . trim($body) . "\n");
        $this->pageCount++;
    }

    private function buildEssentials(): void
    {
        $p    = $this->parser;
        $base = "$this->courseBase/20.essentials";
        $fm   = $this->pageFrontmatter('Essentials', [], true);

        $body = '';

        if ($p->courseTitle) {
            $body .= "## " . $p->courseTitle . "\n\n";
        }
        if ($p->courseCode) {
            $body .= "**Course Code:** " . $p->courseCode . "\n\n";
        }
        if ($p->license) {
            $licenseText = $p->licenseUrl
                ? "[{$p->license}]({$p->licenseUrl})"
                : $p->license;
            $body .= "**License:** " . $licenseText . "\n\n";
        }

        $body .= "> [!TIP]\n> Add a course description, instructor name(s), and contact information here.\n";

        $this->addFile("$base/course-page.md", $fm . $body);
    }

    private function buildSyllabus(): void
    {
        $html = $this->parser->syllabusHtml;
        $base = "$this->courseBase/50.syllabus";
        $fm   = $this->pageFrontmatter('Syllabus', [], true);

        if ($html && strlen(strip_tags($html)) >= 10) {
            $body = $this->convertAndCollectImages($html, $base);
            $this->addFile("$base/course-page.md", $fm . trim($body) . "\n");
        } else {
            $this->addFile("$base/course-page.md", $fm . "> [!TIP]\n> Add syllabus content here.\n");
        }
    }

    // For Open Course Hub: the syllabus content, in a syllabus folder outside pages/, to copy
    // into the site's own Syllabus page (its images are in the same folder)
    private function buildSyllabusForCopying(): void
    {
        $base = 'syllabus';
        $body = $this->convertAndCollectImages($this->parser->syllabusHtml, $base);
        $this->addFile("$base/course-page.md", $this->pageFrontmatter('Syllabus', [], true) . trim($body) . "\n");
    }

    private function buildResources(): void
    {
        $base = "$this->courseBase/40.resources";
        $fm   = $this->pageFrontmatter('Resources', [], true);
        $this->addFile("$base/course-page.md", $fm . "> [!TIP]\n> Add course resources, links, and reference materials here.\n");
    }

    private function buildConversionNotes(): void
    {
        $p    = $this->parser;
        $lines = [];

        $addHeader = function (string $title) use (&$lines) {
            $lines[] = $title;
            $lines[] = str_repeat('-', strlen($title));
        };

        $title = 'Common Cartridge Conversion Notes';
        $lines[] = $title;
        $lines[] = str_repeat('=', strlen($title));
        $lines[] = 'Generated: ' . date('Y-m-d');
        $lines[] = '';

        $addHeader('Course Metadata');
        $lines[] = '  Title:   ' . ($p->courseTitle ?: '(none)');
        $lines[] = '  Code:    ' . ($p->courseCode  ?: '(none)');
        $lines[] = '  License: ' . ($p->license     ?: '(not specified)');
        if ($p->licenseUrl) $lines[] = '  License URL: ' . $p->licenseUrl;
        if ($p->courseImagePath) {
            $lines[] = '  Course image: ' . $this->courseImageFolder() . '/' . basename($p->courseImagePath);
        } elseif ($p->courseImageUrl) {
            $lines[] = '  Course image: ' . $p->courseImageUrl . ' (external URL — not included in ZIP)';
        }
        $lines[] = '';

        $addHeader('Structure');
        $lines[] = '  Modules:    ' . count($p->modules);
        $lines[] = '  Wiki pages: ' . count($p->wikiPages);
        $lines[] = '  Pages created: ' . $this->pageCount;
        $imageCount = count($this->pendingImages);
        if ($imageCount > 0) {
            if ($this->skipImageDownload) {
                $localCount = count(array_filter($this->pendingImages, fn($i) => $i['localPath'] !== null));
                $lines[] = '  Images: ' . $localCount . ' local (included in ZIP); external images use remote URLs';
            } else {
                $failNote = $this->imageFailures > 0 ? '; ' . $this->imageFailures . ' failed (see warnings)' : '';
                $lines[] = '  Images: ' . count($this->imageData) . ' downloaded and included in ZIP' . $failNote;
            }
        }
        if ($this->externalUrlCount > 0) {
            $lines[] = '  External URLs:  ' . $this->externalUrlCount . ' (converted to Markdown links)';
        }
        if ($this->attachmentCount > 0) {
            $included = !$this->skipFiles ? 'included in ZIP under files/' : 'not included';
            $lines[] = '  Attachments:    ' . $this->attachmentCount . ' (' . $included . ')';
        }
        $lines[] = '';

        $addHeader('Dropped Content');
        if (empty($this->droppedByType)) {
            $lines[] = '  None.';
        } else {
            foreach ($this->droppedByType as $type => $count) {
                $lines[] = sprintf('  %-30s %d', $type, $count);
            }
            $lines[] = '';
            $lines[] = '  Total dropped items: ' . $this->droppedCount;
        }
        $lines[] = '';

        $addHeader('Next Steps');
        if ($this->destination === 'open-course-hub') {
            $lines[] = '  1. Copy the 07.modules folder from inside the extracted pages folder into';
            $lines[] = '     your Open Course Hub site\'s user/pages/ directory (replacing the sample';
            $lines[] = '     Modules folder, if your site has one). To move Modules in the menu, change';
            $lines[] = '     the folder\'s number (02.modules puts it first); its page addresses stay the same';
            if (isset($this->files['syllabus/course-page.md'])) {
                $lines[] = '  2. The course syllabus is in the syllabus folder: copy its content (and any';
                $lines[] = '     images) into your site\'s Syllabus page';
            } else {
                $lines[] = '  2. (No syllabus content was found to copy)';
            }
            $courseImageFile = '';
            if ($this->parser->courseImagePath && file_exists($this->parser->courseImagePath)) {
                $courseImageFile = basename($this->parser->courseImagePath);
            }
            if ($courseImageFile) {
                $lines[] = '  3. To use the course image as the site\'s header image, put ' . $courseImageFile;
                $lines[] = '     (in the headerimage folder) in your site\'s user/pages/headerimage/ folder,';
                $lines[] = '     in place of the image already there';
                $lines[] = '  4. Review this file for any warnings or manual fixes needed';
            } else {
                $lines[] = '  3. Review this file for any warnings or manual fixes needed';
            }
        } elseif ($this->destination === 'open-multicourse-hub') {
            $lines[] = '  1. Copy the course folder from inside the extracted pages folder into';
            $lines[] = '     your Open MultiCourse Hub site\'s user/pages/ directory';
            $lines[] = '  2. To set its place in the course list, add a number to the front of the';
            $lines[] = '     folder name (such as 05.), and set its description in subsite.md';
            $lines[] = '  3. Review this file for any warnings or manual fixes needed';
        } elseif ($this->portableMarkdown) {
            $lines[] = '  1. Copy the course folder from inside the extracted pages folder into';
            $lines[] = '     any Grav site\'s user/pages/ directory (no Helios plugin required),';
            $lines[] = '     or adapt it for another Markdown-based platform';
            $lines[] = '  2. Review this file for any warnings or manual fixes needed';
        } else {
            $lines[] = '  1. Copy the course folder from inside the extracted pages folder into';
            $lines[] = '     your Grav Helios Course Hub installation\'s user/pages/ directory';
            $lines[] = '  2. Review this file for any warnings or manual fixes needed';
        }
        $lines[] = '';

        $addHeader('Conversion Settings');
        $destinationName = 'Grav Helios Course Hub';
        if ($this->destination === 'open-course-hub') {
            $destinationName = 'Open Course Hub';
        }
        if ($this->destination === 'open-multicourse-hub') {
            $destinationName = 'Open MultiCourse Hub';
        }
        if ($this->portableMarkdown) {
            $destinationName = 'any Grav site';
        }
        $lines[] = '  Destination:     ' . $destinationName;
        $lines[] = '  Format:          ' . ($this->portableMarkdown
            ? 'Standard Markdown (no Helios shortcodes)'
            : 'Course hub (shortcodes + full frontmatter)');
        $lines[] = '  Attached files:  ' . ($this->skipFiles ? 'skipped' : 'included in ZIP under files/');
        $lines[] = '  Image download:  ' . ($this->skipImageDownload ? 'skipped — images kept as remote URLs' : 'downloaded and bundled in ZIP');
        $lines[] = '  Numbered titles: ' . ($this->stripTitleNumbering ? 'cleaned up (leading numbering stripped)' : 'left as-is');
        if ($this->destination === 'open-course-hub') {
            $lines[] = '  Pages:           Modules only (no Home, Essentials, Resources or Syllabus page)';
        } else {
            $lines[] = '  Essentials page: ' . ($this->includeEssentials ? 'included' : 'not included');
            $lines[] = '  Resources page:  ' . ($this->includeResources ? 'included' : 'not included');
            $lines[] = '  Syllabus page:   ' . ($this->includeSyllabus ? 'included' : 'not included');
        }
        $lines[] = '';

        if (!empty($this->warnings)) {
            $addHeader('Warnings');
            foreach ($this->warnings as $w) {
                $lines[] = '  [warn] ' . $w;
            }
            $lines[] = '';
        }

        $addHeader('Known Limitations');
        $lines[] = '  - Quizzes and discussions are not supported and have been dropped.';
        $lines[] = '  - Internal Canvas page links are rewritten to the converted page when the target is included in this course; otherwise the link points to "#" as a placeholder.';
        $lines[] = '  - LTI tool links appear as plain links to the original tool; authentication context is not preserved.';
        if ($this->portableMarkdown) {
            $lines[] = '  - Internal cross-reference links use Grav-style absolute paths (e.g. /modules/some-page)';
            $lines[] = '    and will not resolve outside a Grav site — check/update these manually if publishing';
            $lines[] = '    to another platform';
        }
        $lines[] = '';

        $this->addFile('conversion-notes.txt', implode("\n", $lines) . "\n");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function convertAndCollectImages(string $html, string $pageFolder): string
    {
        $md = $this->converter->convert($html);
        foreach ($this->converter->pendingImages as $img) {
            $this->pendingImages[] = ['pageFolder' => $pageFolder] + $img;
        }
        foreach ($this->converter->pendingFiles as $file) {
            $this->attachmentFiles[$pageFolder . '/' . $file['filename']] = $file['localPath'];
        }
        return $this->constrainMarkerIcons($md);
    }

    // A small icon immediately paired with a bold label ("<img> <strong>READ</strong>", or a
    // bold span that wraps both together like "**<img> SUBMIT: Due...**") is a marker/bullet
    // idiom, not real content – Canvas's own theme CSS keeps these visually consistent
    // regardless of each icon file's actual pixel dimensions, but that CSS doesn't carry over
    // to a Grav page, so mismatched source files (e.g. one icon authored at 128×111 next to
    // siblings at ~60×50) become visibly inconsistent once rendered. Cap just this pattern to a
    // small fixed width via raw HTML rather than Grav's own "?resize=" URL action syntax, since
    // that's an opt-in setting (off by default) a given Grav site may not have enabled – leaving
    // every other image (real content photos, diagrams) untouched at native size.
    private function constrainMarkerIcons(string $md): string
    {
        // Variant 1: image directly followed by its own short bold label – tolerates either
        // a single space (the source HTML had one, e.g. "<img> <strong>") or a blank-line
        // paragraph break (breakImageFromFollowingText() promotes a zero-space glue to one,
        // since it can't distinguish that case from an unrelated image+caption glued
        // together with no separator). Either way, the label is pulled back inline beside
        // the now width-capped icon rather than left stacked on its own paragraph.
        $md = preg_replace(
            '/!\[([^\]]*)\]\(([^)\s]+)\)(?: ?|\n\n)(\*\*[^*\n]{1,40}\*\*)/',
            '<img src="$2" alt="$1" width="28"> $3',
            $md
        );
        // Variant 2: a bold span that wraps the image and its label together.
        $md = preg_replace(
            '/(\*\*)!\[([^\]]*)\]\(([^)\s]+)\) /',
            '$1<img src="$3" alt="$2" width="28"> ',
            $md
        );
        return $md;
    }

    // Record which Grav route a Canvas wiki-page slug ended up at, so inline
    // $WIKI_REFERENCE$/pages/{slug} links can be resolved once every page exists.
    private function registerRoute(?string $slug, string $zipPath): void
    {
        if (!$slug) return;
        $this->slugToRoute[$slug] = $this->zipPathToRoute($zipPath);
    }

    // Turn a page's file path in the zip into its address on the site, for links between pages.
    // For example, pages/my-course/30.modules/01.week-1/course-page.md becomes /my-course/modules/week-1:
    // the course folder stays, as it's part of the course's addresses, and the page file name and
    // the folder numbers are left out, as Grav leaves them out of page addresses.
    private function zipPathToRoute(string $zipPath): string
    {
        // Step 1: the page's folder, without "pages/" at the start or the file name at the end
        $path = preg_replace('#^pages/#', '', $zipPath);
        $path = dirname($path);

        // Step 2: remove the number from the start of each folder name (01.week-1 -> week-1)
        $segments = [];
        foreach (explode('/', $path) as $folder) {
            $segments[] = preg_replace('/^\d+\./', '', $folder);
        }

        return '/' . implode('/', $segments);
    }

    // Grav gives each page an address from its folder name without the number, so two pages
    // called "Instructions" in the same module (01.instructions and 03.instructions) would share
    // one address, and only one of them could be reached. This keeps each page's slug unique
    // within its parent folder by adding a number to repeats: instructions, instructions-2, instructions-3.
    private function uniqueSlug(string $parentFolder, string $slug): string
    {
        // a title with no letters or numbers gives an empty slug, so use "page" instead
        if ($slug === '') {
            $slug = 'page';
        }

        if (!isset($this->usedSlugs[$parentFolder])) {
            $this->usedSlugs[$parentFolder] = [];
        }

        $uniqueSlug = $slug;
        $count = 2;
        while (in_array($uniqueSlug, $this->usedSlugs[$parentFolder], true)) {
            $uniqueSlug = $slug . '-' . $count;
            $count++;
        }

        $this->usedSlugs[$parentFolder][] = $uniqueSlug;
        return $uniqueSlug;
    }

    // Substitute %%WIKIREF:slug%% placeholders (emitted by ContentConverter for
    // $WIKI_REFERENCE$ links) with the target page's route, now that every page's
    // route is known. This replaces only the placeholder token itself (not the
    // surrounding [text](...) markup), since link text can contain nested Markdown
    // (e.g. an image thumbnail used as the link) that isn't safe to bracket-match with
    // a simple regex. Unresolved slugs (page not included in this course) strip the
    // surrounding [text](%%) link syntax to leave plain text — safer than a dead "#" link.
    private function resolveWikiRefLinks(): void
    {
        foreach ($this->files as $path => $content) {
            if (!str_ends_with($path, '.md') || strpos($content, '%%WIKIREF:') === false) continue;
            // First pass: resolve known slugs to their route
            $resolved = preg_replace_callback(
                '/%%WIKIREF:([^%]+)%%/',
                fn($m) => $this->slugToRoute[$m[1]] ?? '%%DEAD%%',
                $content
            );
            // Second pass: strip dead-link markdown — [text](%%DEAD%%) → text.
            // The character class [^\]\n]+ stops at ']', so image-as-link syntax
            // [![alt](img)](%%DEAD%%) won't match. A final str_replace catches any
            // remaining sentinel and converts it to a safe '#' rather than leaving
            // the literal string in the output.
            $resolved = preg_replace('/\[([^\]\n]+)\]\(%%DEAD%%\)/', '$1', $resolved);
            $resolved = str_replace('%%DEAD%%', '#', $resolved);
            $this->files[$path] = $resolved;
        }
    }

    private function downloadPendingImages(): void
    {
        $deadline = microtime(true) + 90; // 90s total budget for remote image downloads

        foreach ($this->pendingImages as $img) {
            $zipPath = $img['pageFolder'] . '/' . $img['filename'];

            if ($img['localPath'] !== null) {
                // Local files: always include, no timeout needed
                $data = @file_get_contents($img['localPath']);
                if ($data !== false) $this->imageData[$zipPath] = $data;
            } elseif ($img['url'] !== null) {
                if (microtime(true) >= $deadline) {
                    $this->imageFailures++;
                    continue; // count remaining as failures silently
                }
                $data = $this->downloadImage($img['url']);
                if ($data !== null) {
                    $this->imageData[$zipPath] = $data;
                } else {
                    $this->imageFailures++;
                    $this->warnings[] = 'Image download failed: ' . $img['url'];
                }
            }
        }

        if ($this->imageFailures > 0 && microtime(true) >= $deadline) {
            $this->warnings[] = 'Image download time limit reached — some remote images were skipped. Use "Skip image download" to keep remote URLs instead.';
        }
    }

    // Any pendingImage whose data never made it into $imageData failed to resolve (bad or
    // inaccessible URL, download timeout, or failed the content check in downloadImage() —
    // e.g. an authenticated Canvas endpoint that redirected to a login page instead of
    // returning real image bytes). Rather than leave a guaranteed-broken image reference in
    // the final site, drop it back to plain alt text, matching how an unresolvable
    // Canvas-internal reference is already handled elsewhere in ContentConverter.
    private function dropFailedImageReferences(): void
    {
        foreach ($this->pendingImages as $img) {
            $zipPath = $img['pageFolder'] . '/' . $img['filename'];
            if (isset($this->imageData[$zipPath])) continue;

            $path = $img['pageFolder'] . '/course-page.md';
            if (!isset($this->files[$path]) || strpos($this->files[$path], $img['filename']) === false) continue;

            $quoted = preg_quote($img['filename'], '/');
            // Markdown syntax: ![alt](filename)
            $mdPattern = '/!\[([^\]]*)\]\(' . $quoted . '\)/';
            // Raw HTML from constrainMarkerIcons(): <img src="filename" alt="alt" width="28">
            // — a marker-icon reference can already be in this form by the time downloads
            // finish, since that rewrite happens during page-building, before this runs.
            $htmlPattern = '/<img src="' . $quoted . '" alt="([^"]*)" width="\d+">/';

            $content = preg_replace($mdPattern, '$1', $this->files[$path]);
            $content = preg_replace($htmlPattern, '$1', $content);
            $this->files[$path] = $content;
        }
    }

    private function downloadImage(string $url): ?string
    {
        if (!$this->isSafeImageUrl($url)) {
            return null;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_CONNECTTIMEOUT  => 3,   // 3s to establish connection
                CURLOPT_TIMEOUT         => 5,   // 5s total transfer
                CURLOPT_FOLLOWLOCATION  => true,
                CURLOPT_MAXREDIRS       => 3,
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_USERAGENT       => 'Mozilla/5.0 (compatible; CourseHubConverter/1.0)',
            ]);
            $data     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!$data || strlen($data) === 0 || $httpCode !== 200) return null;
            return $this->looksLikeImage($data) ? $data : null;
        }

        // Fallback: file_get_contents (less reliable timeout enforcement)
        $ctx  = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || strlen($data) === 0) return null;
        return $this->looksLikeImage($data) ? $data : null;
    }

    // Validates actual response content rather than trusting the URL or a Content-Type
    // header (which a misbehaving or authenticated endpoint can misreport) — e.g. an
    // authenticated Canvas file-preview URL redirects unauthenticated requests to a login
    // page, which curl/file_get_contents will happily "succeed" in fetching as 200 OK HTML;
    // without this check that HTML would be silently bundled into the ZIP as if it were the
    // image. Checks magic-byte signatures for the common raster formats plus a text-based
    // check for SVG.
    private function looksLikeImage(string $data): bool
    {
        if (strlen($data) < 12) return false;
        if (str_starts_with($data, "\xFF\xD8\xFF")) return true;                          // JPEG
        if (str_starts_with($data, "\x89PNG\x0D\x0A\x1A\x0A")) return true;               // PNG
        if (str_starts_with($data, 'GIF87a') || str_starts_with($data, 'GIF89a')) return true; // GIF
        if (str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') return true; // WEBP
        if (str_starts_with($data, 'BM')) return true;                                     // BMP
        if (str_starts_with($data, "\x00\x00\x01\x00")) return true;                       // ICO
        if (str_starts_with($data, "II*\x00") || str_starts_with($data, "MM\x00*")) return true; // TIFF
        if (substr($data, 4, 4) === 'ftyp' && stripos(substr($data, 8, 8), 'avif') !== false) return true; // AVIF

        $trimmed = ltrim($data);
        if (stripos($trimmed, '<svg') === 0) return true; // bare SVG
        if (stripos($trimmed, '<?xml') === 0) {
            // XML-wrapped SVG — accept only if an <svg tag actually follows nearby; a bare
            // XML declaration alone is more likely a non-image XML/HTML response than an image.
            return stripos(substr($trimmed, 0, 300), '<svg') !== false;
        }

        // No recognized signature. Reject only when the content clearly looks like text or
        // markup — the actual failure mode this check exists to catch (e.g. an authenticated
        // endpoint redirecting to a login page instead of the image). Accept anything else:
        // a real image in a format not explicitly checked above (e.g. HEIC) shouldn't be
        // silently dropped just because its signature isn't in this list.
        if (preg_match('/^\s*<(!doctype|html)/i', $data)) return false;
        if (preg_match('/^[\x09\x0A\x0D\x20-\x7E]+$/', substr($data, 0, 200))) return false;
        return true;
    }

    // SSRF guard: an <img> src comes straight from uploaded course content, so before
    // fetching it, reject anything that isn't plain http(s) (blocks file://, etc. — this
    // matters most for the file_get_contents() fallback above, which would otherwise just
    // read a local file directly) or whose host resolves to a private, loopback, or
    // link-local address (e.g. a cloud metadata endpoint). Same pattern as
    // ZipBuilder::downloadFile() in the Pressbooks converter.
    private function isSafeImageUrl(string $url): bool
    {
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        $ip = gethostbyname($host);
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function getWikiHtml(array $item): string
    {
        $slug = $item['slug'] ?? null;
        if (!$slug) return '';
        return $this->parser->wikiPages[$slug] ?? '';
    }

    private function isBeltEarned(array $mod): bool
    {
        $title = strtolower($mod['title']);
        // Single-item modules that are just "X Earned" or "X Badge" checkpoints
        $supported = ['WikiPage', 'ExternalUrl', 'Attachment'];
        $contentItems = array_filter($mod['items'], fn($i) => in_array($i['type'], $supported, true));
        return count($contentItems) <= 1
            && (strpos($title, 'earned') !== false || strpos($title, 'badge') !== false);
    }

    private function ltiWarning(array $items): string
    {
        $ltiItems = array_filter($items, fn($i) => $i['type'] === 'ContextExternalTool');
        if (empty($ltiItems)) return '';
        $titles = implode(', ', array_map(fn($i) => '"' . $i['title'] . '"', $ltiItems));
        return "\n\n> [!WARNING]\n> The following LTI tool item(s) could not be converted and must be accessed in the original course: " . $titles . "\n";
    }

    private function trackDropped(array $mod): void
    {
        $droppable = ['Quizzes::Quiz', 'DiscussionTopic', 'Assignment', 'ContextExternalTool'];
        foreach ($mod['items'] as $item) {
            if (in_array($item['type'], $droppable, true)) {
                $this->droppedByType[$item['type']] = ($this->droppedByType[$item['type']] ?? 0) + 1;
                $this->droppedCount++;
            }
        }
    }

    // Render an external URL as an embed or link for known video hosts, or a plain link
    private function externalUrlBody(string $url, string $title): string
    {
        if (preg_match('/youtube\.com\/watch\?.*v=([a-zA-Z0-9_-]+)/', $url, $m)
            || preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $m)) {
            return $this->portableMarkdown
                ? $this->converter->youtubeIframe($m[1], $title) . "\n"
                : '[youtube]https://www.youtube.com/watch?v=' . $m[1] . "[/youtube]\n";
        }
        if (str_contains($url, 'vimeo.com')) {
            $label = $title ?: 'Watch video on Vimeo';
            return '> [' . $label . '](' . $url . ")\n";
        }
        return "[$title]($url)\n";
    }

    // Split a module's items into a landing WikiPage (first indent-0 WikiPage) and the rest
    private function splitItems(array $mod): array
    {
        $landingItem = null;
        $childItems  = [];
        foreach ($mod['items'] as $item) {
            if (!in_array($item['type'], ['WikiPage', 'ExternalUrl', 'Attachment'], true)) continue;
            if ($item['type'] === 'WikiPage' && $item['indent'] === 0 && $landingItem === null) {
                $landingItem = $item;
            } else {
                $childItems[] = $item;
            }
        }
        return [$landingItem, $childItems];
    }

    // Build a standard Grav page frontmatter block
    private function pageFrontmatter(string $title, array $extras = [], bool $hideToc = false): string
    {
        if ($this->portableMarkdown) {
            return "---\ntitle: '$title'\n---\n\n";
        }

        $lines = ["---", "title: '$title'", "published: true"];
        foreach ($extras as $line) $lines[] = $line;
        $lines[] = "taxonomy:";
        $lines[] = "    category: docs";
        if ($hideToc) {
            $lines[] = "navigation:";
            $lines[] = "    toc_position: hidden";
        }
        $lines[] = "---";
        return implode("\n", $lines) . "\n\n";
    }

    private function addFile(string $path, string $content): void
    {
        $this->files[$path] = $content;
    }

    private function createZip(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cc_zip_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        foreach ($this->files as $path => $content) {
            $zip->addFromString($path, $content);
        }

        // Include embedded page images (downloaded in downloadPendingImages)
        foreach ($this->imageData as $path => $data) {
            $zip->addFromString($path, $data);
        }

        // Include attachment files if skip_files is off
        foreach ($this->attachmentFiles as $zipPath => $localPath) {
            if (file_exists($localPath)) {
                $zip->addFile($localPath, $zipPath);
            }
        }

        // Include course image as a local file if available
        $imgPath = $this->parser->courseImagePath;
        if ($imgPath && file_exists($imgPath)) {
            $filename = basename($imgPath);
            $zip->addFile($imgPath, $this->courseImageFolder() . "/$filename");
        }

        $zip->close();
        return $tmp;
    }
}
