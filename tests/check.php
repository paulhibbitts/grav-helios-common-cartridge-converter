<?php
// Conversion tests: converts each sample course in tests/samples and compares the result with the saved one in
// tests/expected. Run from the converter's folder, before committing a change:  php tests/check.php
// Every check should say "passed"; anything else lists the files that came out differently.
// When the output changes on purpose, look at the differences, then save the new output:  php tests/check.php --update
//
// The tests work offline: images on other websites aren't downloaded, so a website being slow or changed can't
// make a test fail.

if (PHP_SAPI !== 'cli') {
    exit; // command line only – nothing runs if this file is ever opened in a browser
}

require dirname(__DIR__) . '/vendor/autoload.php';

use CH\CartridgeParser;
use CH\CourseHubBuilder;

// Each check: a sample course and the form's options. The first one uses the form's defaults.
$checks = [
    'teaching-online-open-course-hub' => ['course' => 'introduction-to-teaching-online.imscc', 'destination' => 'open-course-hub', 'portable' => false],
    'teaching-online-helios' => ['course' => 'introduction-to-teaching-online.imscc', 'destination' => 'helios', 'portable' => false],
    'teaching-online-standard-markdown' => ['course' => 'introduction-to-teaching-online.imscc', 'destination' => 'open-course-hub', 'portable' => true],
    'digital-animation-open-multicourse-hub' => ['course' => 'digital-animation.imscc', 'destination' => 'open-multicourse-hub', 'portable' => false],
];

$update = in_array('--update', $argv, true);
$failed = 0;

foreach ($checks as $name => $check) {
    $files = convertCourse(__DIR__ . '/samples/' . $check['course'], $check);
    $expectedFolder = __DIR__ . '/expected/' . $name;
    if ($update) {
        saveFiles($files, $expectedFolder);
        echo "saved   $name (" . count($files) . " files)\n";
        continue;
    }
    $differences = compareWithSaved($files, $expectedFolder);
    if (count($differences) === 0) {
        echo "passed  $name\n";
        continue;
    }
    $failed++;
    echo "FAILED  $name (" . count($differences) . " differences)\n";
    foreach (array_slice($differences, 0, 10) as $difference) {
        echo "          $difference\n";
    }
}

if ($update) {
    echo "\nSaved the new output in tests/expected – check its changes before committing.\n";
    exit(0);
}
if ($failed === 0) {
    echo "\nAll checks passed.\n";
    exit(0);
}
echo "\n$failed check(s) failed. If the change is intended, run: php tests/check.php --update\n";
exit(1);

// ---- helpers ----

// Converts one course with the same steps as convert.php, and returns its files (path in the zip => contents).
// Two things change from day to day or year to year, so they're replaced with a fixed word: the date in the
// conversion notes, and the course's address name, which can end with the current year (→ COURSE-SLUG).
function convertCourse(string $courseFile, array $check): array
{
    $folder = sys_get_temp_dir() . '/converter-check-' . getmypid();
    mkdir($folder);
    $zip = new ZipArchive();
    $zip->open($courseFile);
    $zip->extractTo($folder);
    $zip->close();

    $parser = new CartridgeParser($folder);
    // skip files, skip image downloads, strip title numbering, include the essentials, resources and syllabus pages
    $builder = new CourseHubBuilder($parser, true, true, true, true, true, true, $check['portable'], $check['destination']);
    $zipPath = $builder->build();

    $files = [];
    $zip = new ZipArchive();
    $zip->open($zipPath);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $path = $zip->getNameIndex($i);
        if (str_ends_with($path, '/')) {
            continue;
        }
        $text = preg_replace('/^Generated: \d{4}-\d{2}-\d{2}$/m', 'Generated: DATE', $zip->getFromIndex($i));
        if ($parser->courseSlug !== '') {
            $path = str_replace($parser->courseSlug, 'COURSE-SLUG', $path);
            $text = str_replace($parser->courseSlug, 'COURSE-SLUG', $text);
        }
        $files[$path] = $text;
    }
    $zip->close();
    unlink($zipPath);
    removeFolder($folder);
    ksort($files);
    return $files;
}

// The files that are missing, added or different, compared with the saved output
function compareWithSaved(array $files, string $folder): array
{
    if (!is_dir($folder)) {
        return ['no saved output yet – run: php tests/check.php --update'];
    }
    $saved = [];
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        $saved[substr($item->getPathname(), strlen($folder) + 1)] = file_get_contents($item->getPathname());
    }
    $differences = [];
    foreach (array_diff_key($saved, $files) as $path => $text) {
        $differences[] = "missing: $path";
    }
    foreach (array_diff_key($files, $saved) as $path => $text) {
        $differences[] = "added: $path";
    }
    foreach (array_intersect_key($files, $saved) as $path => $text) {
        if ($text !== $saved[$path]) {
            $differences[] = "changed: $path";
        }
    }
    return $differences;
}

// Replaces a check's saved output with these files
function saveFiles(array $files, string $folder): void
{
    removeFolder($folder);
    foreach ($files as $path => $text) {
        $target = $folder . '/' . $path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        file_put_contents($target, $text);
    }
}

// Deletes a folder and everything in it (the test's temporary folder, or a check's old saved output)
function removeFolder(string $folder): void
{
    if (!is_dir($folder)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($folder);
}
