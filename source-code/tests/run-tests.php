<?php
/**
 * Ajaia Docs test suite — plain PHP CLI, no PHPUnit or Composer needed.
 *
 *   docker compose exec app php tests/run-tests.php
 *
 * Uses a separate database (default "ajaia_test") that is wiped on every run,
 * so development and production data are never touched. Part 1 tests the
 * document/permission layer directly; part 2 starts PHP's built-in server
 * against the test database and checks the real HTTP endpoints.
 */
declare(strict_types=1);

$testDb = getenv('TEST_DB_NAME') ?: 'ajaia_test';
if (!str_ends_with($testDb, '_test')) {
    fwrite(STDERR, "Refusing to run: test database name must end with _test (got '$testDb').\n");
    exit(1);
}
putenv("DB_NAME=$testDb");

require __DIR__ . '/../includes/bootstrap.php';
restore_exception_handler();

// ---------------------------------------------------------------------------
// Tiny test harness
// ---------------------------------------------------------------------------
$results = ['pass' => 0, 'fail' => 0, 'failures' => []];

function test(string $name, callable $fn): void
{
    global $results;
    try {
        $fn();
        $results['pass']++;
        echo "  \033[32m✔\033[0m $name\n";
    } catch (Throwable $e) {
        $results['fail']++;
        $results['failures'][] = $name;
        echo "  \033[31m✘ $name\033[0m\n      " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function assert_true($condition, string $message = 'Expected condition to be true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message ? "$message: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(($message ? "$message: " : '') . "expected to find '$needle' in '" . substr($haystack, 0, 300) . "'");
    }
}

function assert_not_contains(string $needle, string $haystack): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException("did not expect '$needle' in '" . substr($haystack, 0, 300) . "'");
    }
}

/** Assert that $fn throws HttpError with the given status. */
function assert_http_error(int $status, callable $fn): void
{
    try {
        $fn();
    } catch (HttpError $e) {
        assert_same($status, $e->status, 'HTTP status (' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException("Expected HttpError $status, but nothing was thrown");
}

function temp_file(string $contents, string $suffix = ''): string
{
    $path = tempnam(sys_get_temp_dir(), 'ajaia') . $suffix;
    file_put_contents($path, $contents);
    return $path;
}

/** Build a minimal but real .docx file. */
function make_docx(string $bodyXml, ?string $numberingXml = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'ajaia') . '.docx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="' . WORD_NS . '"><w:body>' . $bodyXml . '</w:body></w:document>');
    if ($numberingXml !== null) {
        $zip->addFromString('word/numbering.xml', '<?xml version="1.0" encoding="UTF-8"?><w:numbering xmlns:w="' . WORD_NS . '">' . $numberingXml . '</w:numbering>');
    }
    $zip->close();
    return $path;
}

// ---------------------------------------------------------------------------
// Fresh test database: drop everything, then let auto-install recreate it.
// ---------------------------------------------------------------------------
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_port'], $testDb),
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec('DROP TABLE IF EXISTS document_shares, documents, users');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo = null;

$db = db(); // auto-installs schema + seed
const ALICE = 1;
const BOB = 2;
const CAROL = 3;

echo "Ajaia Docs tests (database: $testDb)\n";

// ---------------------------------------------------------------------------
section('Documents: create, save, reopen');
// ---------------------------------------------------------------------------
test('owner can create a document and save formatted content', function () use ($db) {
    $id = create_document($db, ALICE, 'Launch plan');
    $doc = require_document($db, $id, ALICE);
    assert_same('owner', $doc['role']);
    assert_same('Launch plan', $doc['title']);

    $html = '<h1>Plan</h1><p><strong>Bold</strong>, <em>italic</em> and <u>underlined</u>.</p><ul><li>One</li></ul><ol><li>First</li></ol>';
    $result = save_document_content($db, $id, ALICE, $html, $doc['version']);
    assert_same($doc['version'] + 1, $result['version']);

    $reopened = require_document($db, $id, ALICE);
    assert_same($html, $reopened['content'], 'formatting survives save and reopen');
});

test('a blank new document gets the default title', function () use ($db) {
    $id = create_document($db, ALICE);
    assert_same(DEFAULT_DOCUMENT_TITLE, require_document($db, $id, ALICE)['title']);
});

test('stale autosave (old base_version) is rejected with 409 and does not overwrite', function () use ($db) {
    $id = create_document($db, ALICE, 'Race');
    save_document_content($db, $id, ALICE, '<p>newer</p>', 1);          // -> version 2
    assert_http_error(409, fn () => save_document_content($db, $id, ALICE, '<p>older</p>', 1));
    assert_same('<p>newer</p>', require_document($db, $id, ALICE)['content']);
});

test('saved content is sanitized: scripts, handlers and links are stripped', function () use ($db) {
    $id = create_document($db, ALICE, 'XSS');
    save_document_content($db, $id, ALICE, '<p onclick="x()">Hi<script>alert(1)</script> <a href="javascript:alert(1)">link</a><img src=x onerror=alert(1)></p>', 1);
    $content = require_document($db, $id, ALICE)['content'];
    assert_same('<p>Hi link</p>', $content);
});

test('owner can rename; title is trimmed and whitespace collapsed', function () use ($db) {
    $id = create_document($db, ALICE, 'Old');
    assert_same('New name', rename_document($db, $id, ALICE, "  New \n  name "));
    assert_same('New name', require_document($db, $id, ALICE)['title']);
});

test('owner can delete; shares are removed by cascade', function () use ($db) {
    $id = create_document($db, ALICE, 'To delete');
    share_document($db, $id, ALICE, BOB);
    delete_document($db, $id, ALICE);
    assert_true(find_document_for_user($db, $id, ALICE) === null, 'document gone');
    $count = $db->query("SELECT COUNT(*) FROM document_shares WHERE document_id = $id")->fetchColumn();
    assert_same(0, (int) $count);
});

// ---------------------------------------------------------------------------
section('Sharing and authorization');
// ---------------------------------------------------------------------------
test('unauthorized user cannot read or modify a private document', function () use ($db) {
    $id = create_document($db, ALICE, 'Private', '<p>secret</p>');
    assert_true(find_document_for_user($db, $id, BOB) === null, 'no access row');
    assert_http_error(404, fn () => require_document($db, $id, BOB));
    assert_http_error(404, fn () => save_document_content($db, $id, BOB, '<p>hacked</p>', 1));
    assert_http_error(404, fn () => rename_document($db, $id, BOB, 'hacked'));
    assert_http_error(404, fn () => delete_document($db, $id, BOB));
    assert_http_error(404, fn () => share_document($db, $id, BOB, CAROL));
    assert_http_error(404, fn () => document_sharing_info($db, $id, BOB));
    assert_same('<p>secret</p>', require_document($db, $id, ALICE)['content']);
});

test('shared user can read and edit the document', function () use ($db) {
    $id = create_document($db, ALICE, 'Team doc', '<p>v1</p>');
    assert_true(share_document($db, $id, ALICE, BOB), 'share created');
    $doc = require_document($db, $id, BOB);
    assert_same('editor', $doc['role']);
    assert_same('<p>v1</p>', $doc['content']);
    save_document_content($db, $id, BOB, '<p>edited by <strong>Bob</strong></p>', $doc['version']);
    assert_same('<p>edited by <strong>Bob</strong></p>', require_document($db, $id, ALICE)['content']);
});

test('shared user cannot rename, delete, share or revoke', function () use ($db) {
    $id = create_document($db, ALICE, 'Owner only');
    share_document($db, $id, ALICE, BOB);
    assert_http_error(403, fn () => rename_document($db, $id, BOB, 'Renamed by Bob'));
    assert_http_error(403, fn () => delete_document($db, $id, BOB));
    assert_http_error(403, fn () => share_document($db, $id, BOB, CAROL));
    assert_http_error(403, fn () => revoke_share($db, $id, BOB, BOB));
    $doc = require_document($db, $id, ALICE);
    assert_same('Owner only', $doc['title']);
    assert_same(ALICE, $doc['owner_id']);
    assert_true(find_document_for_user($db, $id, CAROL) === null, 'Carol still has no access');
});

test('revoking access removes the shared user\'s access', function () use ($db) {
    $id = create_document($db, ALICE, 'Revocable');
    share_document($db, $id, ALICE, BOB);
    revoke_share($db, $id, ALICE, BOB);
    assert_http_error(404, fn () => require_document($db, $id, BOB));
    assert_http_error(404, fn () => save_document_content($db, $id, BOB, '<p>x</p>', 1));
    assert_http_error(404, fn () => revoke_share($db, $id, ALICE, BOB)); // already revoked
});

test('duplicate shares are prevented (idempotent grant + unique key)', function () use ($db) {
    $id = create_document($db, ALICE, 'Dupes');
    assert_true(share_document($db, $id, ALICE, BOB) === true, 'first grant creates');
    assert_true(share_document($db, $id, ALICE, BOB) === false, 'second grant is a no-op');
    $count = $db->query("SELECT COUNT(*) FROM document_shares WHERE document_id = $id")->fetchColumn();
    assert_same(1, (int) $count);
    assert_http_error(23000, function () use ($db, $id) {
        try {
            $db->prepare('INSERT INTO document_shares (document_id, user_id) VALUES (?, ?)')->execute([$id, BOB]);
        } catch (PDOException $e) {
            throw new HttpError((int) $e->getCode(), 'unique constraint');
        }
    });
});

test('cannot share with yourself or with a user that does not exist', function () use ($db) {
    $id = create_document($db, ALICE, 'Self');
    assert_http_error(422, fn () => share_document($db, $id, ALICE, ALICE));
    assert_http_error(404, fn () => share_document($db, $id, ALICE, 99999));
});

test('document lists separate owned and shared documents', function () use ($db) {
    $id = create_document($db, CAROL, 'Carol shares with Bob');
    share_document($db, $id, CAROL, BOB);
    $bob = list_documents_for_user($db, BOB);
    $sharedIds = array_column($bob['shared'], 'id');
    $ownedIds = array_column($bob['owned'], 'id');
    assert_true(in_array($id, $sharedIds, true), 'appears in Bob\'s shared list');
    assert_true(!in_array($id, $ownedIds, true), 'not in Bob\'s owned list');
    $row = $bob['shared'][array_search($id, $sharedIds, true)];
    assert_same('Carol Diaz', $row['owner_name']);

    $carol = list_documents_for_user($db, CAROL);
    $own = $carol['owned'][array_search($id, array_column($carol['owned'], 'id'), true)];
    assert_same(1, $own['share_count']);

    $alice = list_documents_for_user($db, ALICE);
    assert_true(!in_array($id, array_column($alice['owned'], 'id'), true) && !in_array($id, array_column($alice['shared'], 'id'), true), 'invisible to Alice');
});

test('sharing info lists people and only offers candidates to the owner', function () use ($db) {
    $id = create_document($db, ALICE, 'Info');
    share_document($db, $id, ALICE, BOB);
    $ownerView = document_sharing_info($db, $id, ALICE);
    assert_true($ownerView['can_manage']);
    assert_same([BOB], array_column($ownerView['shares'], 'user_id'));
    assert_same([CAROL], array_column($ownerView['candidates'], 'user_id'));
    $editorView = document_sharing_info($db, $id, BOB);
    assert_true(!$editorView['can_manage']);
    assert_same([], $editorView['candidates']);
});

// ---------------------------------------------------------------------------
section('Validation');
// ---------------------------------------------------------------------------
test('invalid titles are rejected', function () use ($db) {
    $id = create_document($db, ALICE, 'Valid');
    assert_http_error(422, fn () => rename_document($db, $id, ALICE, ''));
    assert_http_error(422, fn () => rename_document($db, $id, ALICE, "   \t\n "));
    assert_http_error(422, fn () => rename_document($db, $id, ALICE, str_repeat('a', DOCUMENT_TITLE_MAX + 1)));
    assert_http_error(422, fn () => rename_document($db, $id, ALICE, ['array']));
    assert_http_error(422, fn () => rename_document($db, $id, ALICE, null));
    assert_http_error(422, fn () => rename_document($db, $id, ALICE, "bad\xC3\x28utf8"));
    assert_same(str_repeat('é', DOCUMENT_TITLE_MAX), rename_document($db, $id, ALICE, str_repeat('é', DOCUMENT_TITLE_MAX)));
});

test('malformed save payloads are rejected', function () use ($db) {
    $id = create_document($db, ALICE, 'Payloads');
    assert_http_error(422, fn () => save_document_content($db, $id, ALICE, ['not' => 'html'], 1));
    assert_http_error(422, fn () => save_document_content($db, $id, ALICE, '<p>x</p>', '1'));
    assert_http_error(422, fn () => save_document_content($db, $id, ALICE, '<p>x</p>', null));
    assert_http_error(413, fn () => save_document_content($db, $id, ALICE, str_repeat('a', DOCUMENT_CONTENT_MAX_BYTES + 1), 1));
});

test('ids from requests are strictly parsed', function () {
    assert_same(42, parse_id('42'));
    assert_same(42, parse_id(42));
    foreach (['0', '-1', '1.5', '1e3', ' 1', '1 OR 1=1', '', null, [], true, 0, '01'] as $bad) {
        assert_same(null, parse_id($bad), 'parse_id(' . var_export($bad, true) . ')');
    }
});

// ---------------------------------------------------------------------------
section('Sanitizer');
// ---------------------------------------------------------------------------
test('keeps toolbar formatting and normalises equivalent tags', function () {
    assert_same('<p><strong>a</strong><em>b</em><u>c</u></p>', sanitize_html('<div><b>a</b><i>b</i><u>c</u></div>'));
    assert_same('<h3>Deep</h3>', sanitize_html('<h5 class="x">Deep</h5>'));
    assert_same('<ul><li>x</li></ul>', sanitize_html('<ul style="color:red"><li data-x="1">x</li></ul>'));
});

test('removes dangerous content entirely', function () {
    $out = sanitize_html('<p>ok</p><style>p{}</style><iframe src="https://evil"></iframe><svg onload=alert(1)><script>1</script></svg><!-- c --><form><input></form>');
    assert_same('<p>ok</p>', $out);
    assert_not_contains('script', sanitize_html('<scr<script>ipt>alert(1)</script>'));
});

test('escapes text so stored entities stay inert', function () {
    assert_same('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', sanitize_html('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'));
});

// ---------------------------------------------------------------------------
section('File import');
// ---------------------------------------------------------------------------
test('.txt import keeps paragraphs and escapes HTML', function () {
    $file = temp_file("First line\nsecond line\n\n<b>Not bold</b> & more");
    $result = import_file($file, 'meeting_notes.txt');
    assert_same('meeting notes', $result['title']);
    assert_same('<p>First line<br>second line</p><p>&lt;b&gt;Not bold&lt;/b&gt; &amp; more</p>', $result['html']);
});

test('.md import converts headings, lists, quotes and inline formatting', function () {
    $md = "# Title\n\nSome **bold** and *italic* and _also_ text.\n\n## Steps\n\n1. First\n2. Second\n\n- Apple\n- Banana\n\n> Quoted\n\n[Docs](https://example.com) <script>alert(1)</script>";
    $html = import_file(temp_file($md), 'readme.md')['html'];
    assert_contains('<h1>Title</h1>', $html);
    assert_contains('<strong>bold</strong>', $html);
    assert_contains('<em>italic</em>', $html);
    assert_contains('<em>also</em>', $html);
    assert_contains('<h2>Steps</h2>', $html);
    assert_contains('<ol><li>First</li><li>Second</li></ol>', $html);
    assert_contains('<ul><li>Apple</li><li>Banana</li></ul>', $html);
    assert_contains('<blockquote><p>Quoted</p></blockquote>', $html);
    assert_contains('Docs (https://example.com) &lt;script&gt;', $html);
    assert_not_contains('<script', $html);
    assert_not_contains('<a', $html);
});

test('.docx import keeps headings, lists and bold/italic/underline', function () {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('zip extension not installed');
    }
    $body = '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Report</w:t></w:r></w:p>'
        . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Bold</w:t></w:r><w:r><w:t xml:space="preserve"> and </w:t></w:r><w:r><w:rPr><w:i/><w:u w:val="single"/></w:rPr><w:t>styled</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Step one</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Step two</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="2"/></w:numPr></w:pPr><w:r><w:t>Bullet</w:t></w:r></w:p>'
        . '<w:p><w:r><w:t>&lt;script&gt;</w:t></w:r></w:p>';
    $numbering = '<w:abstractNum w:abstractNumId="10"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum>'
        . '<w:abstractNum w:abstractNumId="20"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl></w:abstractNum>'
        . '<w:num w:numId="1"><w:abstractNumId w:val="10"/></w:num><w:num w:numId="2"><w:abstractNumId w:val="20"/></w:num>';
    $result = import_file(make_docx($body, $numbering), 'Quarterly Report.docx');
    assert_same('Quarterly Report', $result['title']);
    assert_same('<h1>Report</h1><p><strong>Bold</strong> and <em><u>styled</u></em></p><ol><li>Step one</li><li>Step two</li></ol><ul><li>Bullet</li></ul><p>&lt;script&gt;</p>', $result['html']);
});

test('unsupported file types are rejected', function () {
    assert_http_error(415, fn () => import_file(temp_file('<h1>x</h1>'), 'page.html'));
    assert_http_error(415, fn () => import_file(temp_file('MZ...'), 'tool.exe'));
    assert_http_error(415, fn () => import_file(temp_file('<?php echo 1;'), 'shell.php'));
    assert_http_error(415, fn () => import_file(temp_file('no extension'), 'README'));
});

test('empty, oversized, binary and fake files are rejected', function () {
    assert_http_error(422, fn () => import_file(temp_file(''), 'empty.txt'));
    assert_http_error(422, fn () => import_file(temp_file("   \n\n  "), 'blank.md'));
    assert_http_error(413, fn () => import_file(temp_file(str_repeat('a', IMPORT_MAX_BYTES + 1)), 'big.txt'));
    $png = "\x89PNG\r\n\x1a\n" . str_repeat("\0\x01\x02", 50);
    $err = null;
    try { import_file(temp_file($png), 'image.txt'); } catch (HttpError $e) { $err = $e->status; }
    assert_true(in_array($err, [415, 422], true), 'binary disguised as .txt rejected (got ' . var_export($err, true) . ')');
    $err = null;
    try { import_file(temp_file("not a zip\n"), 'fake.docx'); } catch (HttpError $e) { $err = $e->status; }
    assert_true(in_array($err, [415, 422], true), 'text renamed to .docx rejected (got ' . var_export($err, true) . ')');
});

// ---------------------------------------------------------------------------
section('HTTP endpoints (built-in server against the test database)');
// ---------------------------------------------------------------------------
$port = (int) (getenv('TEST_HTTP_PORT') ?: 8099);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', APP_ROOT],
    [1 => ['file', sys_get_temp_dir() . '/ajaia-test-server.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/ajaia-test-server.log', 'a']],
    $pipes,
    APP_ROOT,
    array_merge(getenv(), ['DB_NAME' => $testDb, 'APP_DEBUG' => '0'])
);
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) {
        break;
    }
    usleep(100000);
}

/** Minimal HTTP client with a cookie jar. */
final class Client
{
    public array $cookies = [];
    public string $csrf = '';

    public function __construct(private string $base) {}

    public function request(string $method, string $path, $body = null, array $headers = []): array
    {
        $headers[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        if (is_array($body)) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($body);
        }
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
        ]]);
        $responseBody = file_get_contents($this->base . '/' . $path, false, $context);
        $status = 0;
        $location = null;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            } elseif (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) {
                $this->cookies[$m[1]] = $m[2];
            } elseif (preg_match('/^Location:\s*(.+)$/i', $line, $m)) {
                $location = trim($m[1]);
            }
        }
        if (preg_match('/name="csrf-token" content="([a-f0-9]+)"/', (string) $responseBody, $m)) {
            $this->csrf = $m[1];
        }
        return ['status' => $status, 'body' => (string) $responseBody, 'json' => json_decode((string) $responseBody, true), 'location' => $location];
    }

    public function api(string $method, string $path, $body = null, bool $withCsrf = true): array
    {
        return $this->request($method, $path, $body, $withCsrf ? ['X-CSRF-Token: ' . $this->csrf] : []);
    }

    public function login(string $email, string $password = 'demo1234'): array
    {
        $this->request('GET', 'login.php');
        return $this->request('POST', 'login.php', http_build_query(['email' => $email, 'password' => $password, '_csrf' => $this->csrf]), ['Content-Type: application/x-www-form-urlencoded']);
    }
}

try {
    test('anonymous API calls get 401 JSON and pages redirect to login', function () use ($base) {
        $anon = new Client($base);
        $res = $anon->api('GET', 'api/documents.php');
        assert_same(401, $res['status']);
        assert_same(false, $res['json']['ok']);
        $page = $anon->request('GET', 'editor.php?id=1');
        assert_same(302, $page['status']);
        assert_contains('login.php', (string) $page['location']);
    });

    test('wrong password is refused; correct password signs in', function () use ($base) {
        $client = new Client($base);
        $bad = $client->login('bob@ajaia.demo', 'wrong-password');
        assert_same(200, $bad['status']);
        assert_contains('not correct', $bad['body']);
        $good = $client->login('bob@ajaia.demo');
        assert_same(302, $good['status']);
        assert_same(200, $client->api('GET', 'api/documents.php')['status']);
    });

    $bob = new Client($base);
    $bob->login('bob@ajaia.demo');
    $bob->request('GET', 'dashboard.php'); // refresh CSRF token after session regeneration

    test('Bob cannot open Alice\'s private document by changing the URL', function () use ($bob) {
        assert_same(404, $bob->request('GET', 'editor.php?id=2')['status']);
        assert_same(404, $bob->api('GET', 'api/shares.php?id=2')['status']);
        assert_same(404, $bob->api('POST', 'api/save-document.php', ['id' => 2, 'content' => '<p>hacked</p>', 'base_version' => 1])['status']);
        assert_same(404, $bob->api('POST', 'api/delete-document.php', ['id' => 2])['status']);
        $docs = $bob->api('GET', 'api/documents.php')['json']['data'];
        assert_true(!in_array(2, array_column(array_merge($docs['owned'], $docs['shared']), 'id'), true), 'not listed');
    });

    test('Bob can open and save the document Alice shared with him, but not rename it', function () use ($bob) {
        $page = $bob->request('GET', 'editor.php?id=1');
        assert_same(200, $page['status']);
        assert_contains('Shared by Alice Morgan', $page['body']);
        preg_match('/data-version="(\d+)"/', $page['body'], $m);
        $save = $bob->api('POST', 'api/save-document.php', ['id' => 1, 'content' => '<p>Bob was here</p>', 'base_version' => (int) $m[1]]);
        assert_same(200, $save['status']);
        assert_same((int) $m[1] + 1, $save['json']['data']['version']);
        assert_same(403, $bob->api('POST', 'api/rename-document.php', ['id' => 1, 'title' => 'Mine now'])['status']);
        assert_same(403, $bob->api('POST', 'api/share-document.php', ['id' => 1, 'user_id' => 3])['status']);
    });

    test('state-changing requests without a valid CSRF token are refused', function () use ($bob) {
        $res = $bob->api('POST', 'api/save-document.php', ['id' => 1, 'content' => '<p>x</p>', 'base_version' => 1], false);
        assert_same(403, $res['status']);
        $res = $bob->request('POST', 'api/save-document.php', json_encode(['id' => 1]), ['Content-Type: application/json', 'X-CSRF-Token: forged']);
        assert_same(403, $res['status']);
    });

    test('wrong methods, malformed JSON and bad ids are rejected', function () use ($bob) {
        assert_same(405, $bob->api('GET', 'api/save-document.php')['status']);
        assert_same(400, $bob->request('POST', 'api/save-document.php', '{not json', ['Content-Type: application/json', 'X-CSRF-Token: ' . $bob->csrf])['status']);
        assert_same(400, $bob->api('POST', 'api/save-document.php', ['id' => 'abc', 'content' => '', 'base_version' => 1])['status']);
        assert_same(404, $bob->request('GET', 'editor.php?id=abc')['status']);
    });

    test('Alice shares and revokes over HTTP; Bob\'s access follows', function () use ($base, $bob) {
        $alice = new Client($base);
        $alice->login('alice@ajaia.demo');
        $alice->request('GET', 'dashboard.php');
        assert_same(422, $alice->api('POST', 'api/rename-document.php', ['id' => 2, 'title' => '   '])['status']);
        assert_same(201, $alice->api('POST', 'api/share-document.php', ['id' => 2, 'user_id' => 2])['status']);
        assert_same(200, $bob->request('GET', 'editor.php?id=2')['status']);
        assert_same(200, $alice->api('POST', 'api/revoke-share.php', ['id' => 2, 'user_id' => 2])['status']);
        assert_same(404, $bob->request('GET', 'editor.php?id=2')['status']);
    });

    test('config, includes and SQL files are not served as content', function () use ($base) {
        $anon = new Client($base);
        $res = $anon->request('GET', 'config/config.php');
        assert_not_contains('db_pass', $res['body']);
        $res = $anon->request('GET', 'includes/db.php');
        assert_not_contains('PDO', $res['body']);
    });
} finally {
    proc_terminate($server);
}

// ---------------------------------------------------------------------------
$total = $results['pass'] + $results['fail'];
echo "\n{$results['pass']}/$total tests passed";
if ($results['fail'] > 0) {
    echo ", {$results['fail']} failed:\n - " . implode("\n - ", $results['failures']) . "\n";
    exit(1);
}
echo "\n";
exit(0);
