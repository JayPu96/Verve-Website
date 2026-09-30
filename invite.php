<?php
// Per-invite link previews for https://getverveapp.com/invite/<token>.
//
// Messages, WhatsApp, Slack and the rest build a link's preview from the
// page's Open Graph tags without running JavaScript, so each invite's name and
// picture have to be in the HTML itself. This serves invite.html with the
// invite's own title, description and image from invitePreview
// (functions/index.js in the app repo), and hands the page the same data so it
// doesn't fetch it again. The image (shareImageURL) is the trip's card from the
// app's Share Trip sheet, drawn by the inviteCard function; since it shows the
// trip's name, the caption says "Join my trip on Verve".
//
// If anything goes wrong (a malformed token, the function unreachable), this
// serves invite.html untouched: the generic preview, and the page loads the
// invite itself.

ini_set('display_errors', '0');

const PREVIEW_ENDPOINT = 'https://us-central1-verve-94117.cloudfunctions.net/invitePreview';
const SITE = 'https://getverveapp.com';
const DEFAULT_IMAGE = SITE . '/images/og-invite.jpg';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
// Short, like invitePreview's own: edits and revoked links show up quickly.
header('Cache-Control: public, max-age=60');

$page = file_get_contents(__DIR__ . '/invite.html');
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);

// Same token rule as invite.html, UniversalLink.swift and invitePreview.
if (!is_string($path) || !preg_match('#^/invite/([A-Za-z0-9-]{1,64})/?$#', $path, $match)) {
    echo $page;
    exit;
}
$token = $match[1];

list($status, $invite) = fetch_invite($token);
if ($status === 0) {
    echo $page;
    exit;
}

$tags = $status === 200 ? invite_tags($token, $invite) : inactive_tags($token);
$data = json_encode(
    array('status' => $status, 'invite' => $invite),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);

$page = replace_between($page, '<!-- preview:start', '<!-- preview:end -->', $tags);
if ($data !== false) {
    $page = replace_between($page, '<!-- invite:data', '-->', '<script>window.VERVE_INVITE = ' . $data . ';</script>');
}
echo $page;

/**
 * Asks invitePreview for the invite: [200, invite], [404 or 400, null], or
 * [0, null] when there's no usable answer.
 */
function fetch_invite($token)
{
    if (!function_exists('curl_init')) {
        return array(0, null);
    }
    $request = curl_init(PREVIEW_ENDPOINT . '?token=' . rawurlencode($token));
    curl_setopt_array($request, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        // A cold function can take a few seconds; previewers wait longer than this.
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
    ));
    $body = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);

    if ($status === 200 && is_string($body)) {
        $invite = json_decode($body, true);
        return is_array($invite) ? array(200, $invite) : array(0, null);
    }
    return ($status === 404 || $status === 400) ? array($status, null) : array(0, null);
}

/** The preview tags for a live invite. */
function invite_tags($token, $invite)
{
    $tripName = field($invite, 'tripName', 120);
    $title = $tripName !== '' ? $tripName : 'You’re invited to a trip on Verve';

    $inviter = field($invite, 'fromDisplayName', 60);
    $preview = isset($invite['preview']) && is_array($invite['preview']) ? $invite['preview'] : array();
    $invited = $tripName === ''
        ? ($inviter !== '' ? $inviter . ' invited you on Verve' : 'You’re invited on Verve')
        : ($inviter !== '' ? $inviter . ' invited you to ' . $tripName : 'You’re invited to ' . $tripName);
    $description = implode(' · ', array_filter(array($invited, field($preview, 'dateSummary', 60)), 'strlen'));

    $image = field($invite, 'shareImageURL', 1000);
    $width = isset($invite['shareImageWidth']) && is_int($invite['shareImageWidth']) ? $invite['shareImageWidth'] : 0;
    $height = isset($invite['shareImageHeight']) && is_int($invite['shareImageHeight']) ? $invite['shareImageHeight'] : 0;
    $cardShowsName = isset($invite['shareImageShowsTitle']) && $invite['shareImageShowsTitle'] === true;
    if (!preg_match('#^https://[^\s"<>]+$#', $image) || $width <= 0 || $height <= 0) {
        list($image, $width, $height) = array(DEFAULT_IMAGE, 1200, 630);
        $cardShowsName = false;
    }
    // The card already shows the trip's name, so the caption under it (Messages
    // prints og:title there) asks instead of repeating it. Each link is one
    // person's, so "my" is theirs. When the card can't draw the name (a script
    // its fonts don't cover), or there's no card, the caption carries the name.
    $caption = $cardShowsName ? 'Join my trip on Verve' : $title;

    // The card's background photo, credited when it's from Unsplash.
    $author = field($invite, 'backgroundUnsplashAuthor', 100);
    $fromUnsplash = strpos(field($invite, 'backgroundUnsplashURL', 1000), 'https://images.unsplash.com/') === 0;
    $alt = $title . ($fromUnsplash && $author !== '' ? '. Photo by ' . $author . ' on Unsplash' : '');

    return preview_tags($token, 'Join ' . $title . ' on Verve', $caption, $description, array($image, $width, $height), $alt);
}

/** Revoked or unknown links: say so, and show nothing about the trip. */
function inactive_tags($token)
{
    return preview_tags(
        $token,
        'Verve',
        'This invite isn’t active',
        'Ask whoever sent it for a new link.',
        array(DEFAULT_IMAGE, 1200, 630),
        'The Verve app icon'
    );
}

/** $picture: [url, width, height]. */
function preview_tags($token, $pageTitle, $title, $description, $picture, $alt)
{
    list($image, $width, $height) = $picture;
    $url = SITE . '/invite/' . $token;
    $lines = array(
        '<title>' . esc($pageTitle) . '</title>',
        '<meta name="description" content="' . esc($description) . '">',
        '<meta property="og:type" content="website">',
        '<meta property="og:site_name" content="Verve">',
        '<meta property="og:url" content="' . esc($url) . '">',
        '<meta property="og:title" content="' . esc($title) . '">',
        '<meta property="og:description" content="' . esc($description) . '">',
        '<meta property="og:image" content="' . esc($image) . '">',
        '<meta property="og:image:width" content="' . (int) $width . '">',
        '<meta property="og:image:height" content="' . (int) $height . '">',
        '<meta property="og:image:alt" content="' . esc($alt) . '">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="' . esc($title) . '">',
        '<meta name="twitter:description" content="' . esc($description) . '">',
        '<meta name="twitter:image" content="' . esc($image) . '">',
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

/** $html with everything from $start through $end replaced (unchanged if either is missing). */
function replace_between($html, $start, $end, $replacement)
{
    $from = strpos($html, $start);
    $to = $from === false ? false : strpos($html, $end, $from);
    if ($from === false || $to === false) {
        return $html;
    }
    return substr($html, 0, $from) . $replacement . substr($html, $to + strlen($end));
}
