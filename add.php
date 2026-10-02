<?php
// Link previews for friend links: https://getverveapp.com/add/<user id>.
//
// Each person has one, from the app's Invite Friends sheet; tapping it opens
// their profile in Verve to add them. Messages draws its bubble from the card
// the app hands the share sheet, so this is for everything else (WhatsApp,
// Slack, Mail…) and for people without Verve: it serves invite.html with the
// person's name in the preview, and hands the page the same data so it doesn't
// fetch it again. Only the name and emoji are public (friendInvitePreview in
// functions/index.js of the app repo).
//
// If the name can't be fetched, the preview leaves it out and the page asks
// for it itself.

ini_set('display_errors', '0');

const PREVIEW_ENDPOINT = 'https://us-central1-verve-94117.cloudfunctions.net/friendInvitePreview';
const SITE = 'https://getverveapp.com';
const DEFAULT_IMAGE = SITE . '/images/og-invite.jpg';
// MUST MATCH FriendInviteShareItem.title in the app (and INVITE_TITLE in functions/friendInvite.js).
const INVITE_TITLE = 'Add me as a friend on Verve';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: public, max-age=300');

$page = file_get_contents(__DIR__ . '/invite.html');
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);

// Same rule as invite.html, UniversalLink.swift and friendInvitePreview.
if (!is_string($path) || !preg_match('#^/add/([A-Za-z0-9-]{1,64})/?$#', $path, $match)) {
    echo $page;
    exit;
}
$uid = $match[1];

list($status, $friend) = fetch_friend($uid);
if ($status === 0) {
    // No answer: still a friend link's preview, without the name. The page
    // asks for the name itself.
    echo replace_between($page, '<!-- preview:start', '<!-- preview:end -->', friend_tags($uid, array()));
    exit;
}

$tags = $status === 200 ? friend_tags($uid, $friend) : inactive_tags($uid);
$data = json_encode(
    array('status' => $status, 'friend' => $friend),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);

$page = replace_between($page, '<!-- preview:start', '<!-- preview:end -->', $tags);
if ($data !== false) {
    $page = replace_between($page, '<!-- friend:data', '-->', '<script>window.VERVE_FRIEND = ' . $data . ';</script>');
}
echo $page;

/** [200, friend], [404 or 400, null], or [0, null] when there's no usable answer. */
function fetch_friend($uid)
{
    if (!function_exists('curl_init')) {
        return array(0, null);
    }
    $request = curl_init(PREVIEW_ENDPOINT . '?uid=' . rawurlencode($uid));
    curl_setopt_array($request, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
    ));
    $body = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);

    if ($status === 200 && is_string($body)) {
        $friend = json_decode($body, true);
        return is_array($friend) ? array(200, $friend) : array(0, null);
    }
    return ($status === 404 || $status === 400) ? array($status, null) : array(0, null);
}

function friend_tags($uid, $friend)
{
    $name = field($friend, 'displayName', 60);
    $pageTitle = $name !== '' ? 'Add ' . $name . ' on Verve' : 'Let’s be friends on Verve';
    $description = ($name !== '' ? $name : 'A friend') . ' wants to plan trips with you on Verve.';
    return preview_tags($uid, $pageTitle, INVITE_TITLE, $description);
}

function inactive_tags($uid)
{
    return preview_tags($uid, 'Verve', 'This link isn’t active', 'Ask whoever sent it to share their link again.');
}

function preview_tags($uid, $pageTitle, $title, $description)
{
    $url = SITE . '/add/' . $uid;
    $lines = array(
        '<title>' . esc($pageTitle) . '</title>',
        '<meta name="description" content="' . esc($description) . '">',
        '<meta property="og:type" content="website">',
        '<meta property="og:site_name" content="Verve">',
        '<meta property="og:url" content="' . esc($url) . '">',
        '<meta property="og:title" content="' . esc($title) . '">',
        '<meta property="og:description" content="' . esc($description) . '">',
        '<meta property="og:image" content="' . esc(DEFAULT_IMAGE) . '">',
        '<meta property="og:image:width" content="1200">',
        '<meta property="og:image:height" content="630">',
        '<meta property="og:image:alt" content="The Verve app icon">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="' . esc($title) . '">',
        '<meta name="twitter:description" content="' . esc($description) . '">',
        '<meta name="twitter:image" content="' . esc(DEFAULT_IMAGE) . '">',
    );
    return implode("\n    ", $lines);
}

/** A trimmed string field, at most $max characters (UTF-8 safe), or "". */
function field($values, $key, $max)
{
    if (!isset($values[$key]) || !is_string($values[$key])) {
        return '';
    }
    $value = trim(preg_replace('/\s+/u', ' ', $values[$key]));
    return preg_match('/^.{0,' . (int) $max . '}/su', $value, $cut) ? $cut[0] : '';
}

function esc($value)
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function replace_between($html, $start, $end, $replacement)
{
    $from = strpos($html, $start);
    $to = $from === false ? false : strpos($html, $end, $from);
    if ($from === false || $to === false) {
        return $html;
    }
    return substr($html, 0, $from) . $replacement . substr($html, $to + strlen($end));
}
