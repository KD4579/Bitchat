<?php
/**
 * Sitemap generator for Google Search Console. CLI only (KamalDave cron, daily):
 *   cd <docroot> && php tools/sitemap/generate_sitemap.php
 *
 * Writes sitemap.xml (index) + sitemaps/*.xml with real lastmod dates.
 * Only real, public, useful URLs: public posts with content, blog articles,
 * pages, public groups, and profiles of people who have posted publicly.
 * Skips fake, banned and inactive accounts (thin pages hurt SEO).
 * Replaces the admin "Generate sitemap" tool, whose index pointed at /xml/
 * (blocked in robots.txt) while the files were written to the web root.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__, 2));
require 'assets/init.php';

$site  = rtrim($wo['config']['site_url'], '/');
$dir   = 'sitemaps';
$limit = 45000; // Google's hard limit is 50,000 URLs per file
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$files = array();

function sm_write($dir, $name, $urls) {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        $xml .= '<url><loc>' . htmlspecialchars($u[0], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
              . (!empty($u[1]) ? '<lastmod>' . gmdate('Y-m-d', $u[1]) . '</lastmod>' : '')
              . '</url>' . "\n";
    }
    $xml .= '</urlset>' . "\n";
    $tmp = "$dir/$name.tmp";
    file_put_contents($tmp, $xml);
    rename($tmp, "$dir/$name");
    return $name;
}

function sm_chunks($dir, $prefix, $urls, $limit, &$files) {
    foreach (array_chunk($urls, $limit) as $i => $chunk) {
        $name = $prefix . '-' . ($i + 1) . '.xml';
        sm_write($dir, $name, $chunk);
        $last = max(array_map(function ($u) { return intval($u[1]); }, $chunk));
        $files[] = array($name, $last);
    }
}

$good_user = "u.active = '1' AND u.banned = '0' AND u.src <> 'Fake'";

// Static pages
$static = array(array($site . '/', time()));
foreach (array('terms/about-us', 'contact-us', 'terms/privacy-policy', 'terms/terms') as $p) {
    $static[] = array($site . '/' . $p, 0);
}
sm_chunks($dir, 'sitemap-main', $static, $limit, $files);

// Public posts with content, newest first
$urls = array();
$r = mysqli_query($sqlConnect, "SELECT p.id, p.time FROM " . T_POSTS . " p JOIN " . T_USERS . " u ON u.user_id = p.user_id
    WHERE p.postPrivacy = '0' AND p.id = p.post_id AND (p.postText <> '' OR p.postFile <> '' OR p.postLink <> '') AND {$good_user}
    ORDER BY p.id DESC");
while ($r && ($row = mysqli_fetch_assoc($r))) {
    $urls[] = array($site . '/post/' . $row['id'], intval($row['time']));
}
sm_chunks($dir, 'sitemap-posts', $urls, $limit, $files);

// Blog articles
$urls = array();
$r = mysqli_query($sqlConnect, "SELECT id, title, posted FROM " . T_BLOG . " WHERE active = '1' ORDER BY id DESC");
while ($r && ($row = mysqli_fetch_assoc($r))) {
    $urls[] = array($site . '/read-blog/' . $row['id'] . '_' . Wo_SlugPost($row['title']), intval($row['posted']));
}
if ($urls) {
    sm_chunks($dir, 'sitemap-articles', $urls, $limit, $files);
}

// Pages + public groups
$urls = array();
$r = mysqli_query($sqlConnect, "SELECT page_name FROM " . T_PAGES . " WHERE active = '1'");
while ($r && ($row = mysqli_fetch_assoc($r))) {
    $urls[] = array($site . '/' . rawurlencode($row['page_name']), 0);
}
$r = mysqli_query($sqlConnect, "SELECT group_name FROM " . T_GROUPS . " WHERE active = '1' AND privacy = '1'");
while ($r && ($row = mysqli_fetch_assoc($r))) {
    $urls[] = array($site . '/' . rawurlencode($row['group_name']), 0);
}
if ($urls) {
    sm_chunks($dir, 'sitemap-communities', $urls, $limit, $files);
}

// Profiles of people who have posted publicly
$urls = array();
$r = mysqli_query($sqlConnect, "SELECT u.username, MAX(p.time) last_post FROM " . T_USERS . " u
    JOIN " . T_POSTS . " p ON p.user_id = u.user_id AND p.postPrivacy = '0'
    WHERE {$good_user} GROUP BY u.user_id ORDER BY last_post DESC");
while ($r && ($row = mysqli_fetch_assoc($r))) {
    $urls[] = array($site . '/' . rawurlencode($row['username']), intval($row['last_post']));
}
sm_chunks($dir, 'sitemap-profiles', $urls, $limit, $files);

// Remove files from earlier runs that are no longer listed
$keep = array_column($files, 0);
foreach (glob("$dir/sitemap-*.xml") as $old) {
    if (!in_array(basename($old), $keep)) {
        unlink($old);
    }
}

// Index at /sitemap.xml
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
     . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($files as $f) {
    $xml .= '<sitemap><loc>' . $site . '/' . $dir . '/' . $f[0] . '</loc>'
          . ($f[1] ? '<lastmod>' . gmdate('Y-m-d', $f[1]) . '</lastmod>' : '') . '</sitemap>' . "\n";
}
$xml .= '</sitemapindex>' . "\n";
file_put_contents('sitemap.xml.tmp', $xml);
rename('sitemap.xml.tmp', 'sitemap.xml');

$total = 0;
foreach ($files as $f) {
    $total += substr_count(file_get_contents("$dir/{$f[0]}"), '<url>');
}
echo gmdate('c') . ' sitemap: ' . count($files) . " files, $total URLs\n";
