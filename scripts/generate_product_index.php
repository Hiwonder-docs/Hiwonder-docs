<?php

declare(strict_types = 1);

const CARD_STYLE = 'width: calc(18% - 15px); min-width: 150px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);';
const CARD_CONTENT_STYLE = 'display: block; text-decoration: none;';
const IMAGE_STYLE = 'width: 100%; height: auto; display: block;';
const LABEL_STYLE = 'padding: 12px 4px; text-align: center; color: #333;';
const GRID_STYLE = 'display: flex; flex-wrap: wrap; gap: 20px; margin-bottom:50px;';

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function decodeMarkdownText(string $value): string
{
    $value = str_replace('\\|', '|', trim($value));
    return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function productImageFilename(string $productName, string $imageUrl): string
{
    $asciiName = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $productName);
    $slug = strtolower((string) $asciiName);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'product';
    }

    return substr($slug, 0, 72) . '-' . substr(sha1($imageUrl), 0, 10) . '.webp';
}

function topCategoryDirectory(string $name): string
{
    $directory = preg_replace('/[<>:"\/\\|?*]+/u', '_', trim($name)) ?? '';
    if ($directory === '') {
        fail("Invalid top-level category directory: {$name}");
    }

    return $directory;
}

function parseCatalog(string $catalogFile): array
{
    $lines = file($catalogFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fail("Unable to read catalog: {$catalogFile}");
    }

    $catalog = [];
    $topIndex = null;
    $categoryIndex = null;

    foreach ($lines as $line) {
        if (preg_match('/^## (.+)$/u', $line, $match)) {
            $catalog[] = ['name' => trim($match[1]), 'categories' => []];
            $topIndex = array_key_last($catalog);
            $categoryIndex = null;
            continue;
        }

        if (preg_match('/^### (.+)$/u', $line, $match) && $topIndex !== null) {
            $catalog[$topIndex]['categories'][] = ['name' => trim($match[1]), 'products' => []];
            $categoryIndex = array_key_last($catalog[$topIndex]['categories']);
            continue;
        }

        if ($topIndex === null || $categoryIndex === null || !str_starts_with($line, '| ')) {
            continue;
        }

        if (!preg_match('/^\| (.*?) \| (.*?) \| (<img .*) \|$/u', $line, $match)) {
            continue;
        }

        if (!preg_match('/src="([^"]+)"/u', $match[3], $imageMatch)) {
            fail("Product image URL is missing: {$line}");
        }

        $link = '';
        if (preg_match('/<a href="([^"]+)"/u', $match[2], $linkMatch)) {
            $link = html_entity_decode($linkMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $catalog[$topIndex]['categories'][$categoryIndex]['products'][] = [
            'name' => decodeMarkdownText($match[1]),
            'link' => $link,
            'image' => html_entity_decode($imageMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];
    }

    return $catalog;
}

function renderProduct(array $product, string $imagePath): array
{
    $name = html($product['name']);
    $lines = [
        '        <div style="' . CARD_STYLE . '">',
    ];

    if ($product['link'] !== '') {
        $lines[] = '            <a target="_blank" href="' . html($product['link']) . '" style="' . CARD_CONTENT_STYLE . '">';
        $closingTag = 'a';
    } else {
        $lines[] = '            <div style="' . CARD_CONTENT_STYLE . '">';
        $closingTag = 'div';
    }

    $lines[] = '                <img src="' . html($imagePath) . '" alt="' . $name . '" style="' . IMAGE_STYLE . '">';
    $lines[] = '                <div style="' . LABEL_STYLE . '">';
    $lines[] = '                    <span>' . $name . '</span>';
    $lines[] = '                </div>';
    $lines[] = '            </' . $closingTag . '>';
    $lines[] = '        </div>';

    return $lines;
}

function buildIndex(array $catalog, string $imageDirectory): array
{
    $lines = [
        '```{title} Hiwonder Docs',
        '```',
        '',
    ];
    $images = [];
    $cardCount = 0;
    $linkedCardCount = 0;

    foreach ($catalog as $topIndex => $top) {
        $topDirectory = topCategoryDirectory($top['name']);

        if ($topIndex > 0) {
            $lines[] = '---';
            $lines[] = '';
        }

        $lines[] = '#  ' . $top['name'];
        $lines[] = '';

        foreach ($top['categories'] as $category) {
            $lines[] = '## ' . $category['name'];
            $lines[] = '';

            if ($category['products'] === []) {
                continue;
            }

            $lines[] = '<div class="container">';
            $lines[] = '    <div style="' . GRID_STYLE . '">';

            foreach ($category['products'] as $product) {
                $filename = productImageFilename($product['name'], $product['image']);
                $relativeImagePath = './_static/images/' . rawurlencode($topDirectory) . '/' . $filename;
                array_push($lines, ...renderProduct($product, $relativeImagePath));
                $imageKey = $topDirectory . "\0" . $product['image'];
                $images[$imageKey] = [
                    'url' => $product['image'],
                    'filename' => $filename,
                    'output' => rtrim($imageDirectory, '/\\') . '/' . $topDirectory . '/' . $filename,
                    'curl_output' => 'source/_static/images/' . $topDirectory . '/' . $filename,
                ];
                $cardCount++;
                if ($product['link'] !== '') {
                    $linkedCardCount++;
                }
            }

            $lines[] = '    </div>';
            $lines[] = '</div>';
            $lines[] = '';
        }
    }

    return [$lines, $images, $cardCount, $linkedCardCount];
}

function curlConfig(array $images): string
{
    $lines = [];
    foreach ($images as $image) {
        $url = $image['url'];
        $separator = str_contains($url, '?') ? '&' : '?';
        $downloadUrl = $url . $separator . 'imageView2/0/format/webp/q/75%7Cimageslim';
        $lines[] = 'url = "' . addcslashes($downloadUrl, '"\\') . '"';
        $lines[] = 'output = "' . addcslashes($image['curl_output'], '"\\') . '"';
    }

    return implode(PHP_EOL, $lines) . ($lines === [] ? '' : PHP_EOL);
}

function seedCategorizedImages(array $images, string $imageDirectory): int
{
    $copied = 0;
    foreach ($images as $image) {
        $targetDirectory = dirname($image['output']);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            fail("Unable to create category image directory: {$targetDirectory}");
        }

        if (is_file($image['output']) && filesize($image['output']) > 0) {
            continue;
        }

        $flatImage = rtrim($imageDirectory, '/\\') . '/' . $image['filename'];
        if (is_file($flatImage) && !copy($flatImage, $image['output'])) {
            fail("Unable to copy product image: {$flatImage}");
        }

        if (is_file($image['output']) && filesize($image['output']) > 0) {
            $copied++;
        }
    }

    return $copied;
}

$catalogFile = $argv[1] ?? '';
$projectRoot = dirname(__DIR__);
$outputFile = $projectRoot . '/source/new-index.md';
$imageDirectory = $projectRoot . '/source/_static/images';
$curlConfigFile = $projectRoot . '/scripts/product-images.curl';

if ($catalogFile === '' || !is_file($catalogFile)) {
    fail('Usage: php scripts/generate_product_index.php <learn-product-catalog.md>');
}

$catalog = parseCatalog($catalogFile);
if (count($catalog) !== 6) {
    fail('Expected 6 top-level categories, found ' . count($catalog) . '.');
}

if (!is_dir($imageDirectory) && !mkdir($imageDirectory, 0777, true) && !is_dir($imageDirectory)) {
    fail("Unable to create image directory: {$imageDirectory}");
}

[$indexLines, $images, $cardCount, $linkedCardCount] = buildIndex($catalog, $imageDirectory);
$copiedImageCount = seedCategorizedImages($images, $imageDirectory);
file_put_contents($outputFile, implode(PHP_EOL, $indexLines));
file_put_contents($curlConfigFile, curlConfig($images));

$categoryCount = array_sum(array_map(static fn(array $top): int => count($top['categories']), $catalog));
$uniqueImageCount = count(array_unique(array_column($images, 'url')));
printf(
    "Generated %d top-level categories, %d second-level categories, %d cards (%d linked), %d categorized image files (%d unique sources, %d copied).%s",
    count($catalog),
    $categoryCount,
    $cardCount,
    $linkedCardCount,
    count($images),
    $uniqueImageCount,
    $copiedImageCount,
    PHP_EOL
);
