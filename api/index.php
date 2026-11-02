<?php
declare(strict_types=1);

// One PHP endpoint; no Node.js, URL rewriting, or database service is required.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

const HPO_GUARD = "<?php http_response_code(404); exit; ?>\n";
const HPO_SESSION_LIFETIME = 43200;
const HPO_MAX_IMAGE = 8388608;

final class ApiError extends RuntimeException
{
    public int $status;
    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function fail_request(int $status, string $message): never
{
    throw new ApiError($status, $message);
}

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function ensure_directory(string $directory, int $permissions = 0750): void
{
    if (!is_dir($directory) && !@mkdir($directory, $permissions, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder penyimpanan tidak dapat dibuat: ' . $directory);
    }
    if (!is_writable($directory)) {
        fail_request(500, 'Folder penyimpanan tidak dapat ditulis. Periksa izin folder melalui File Manager hosting.');
    }
}

function read_private(string $file, mixed $fallback = null): mixed
{
    if (!is_file($file)) {
        return $fallback;
    }
    $content = file_get_contents($file);
    if ($content === false || !str_starts_with($content, HPO_GUARD)) {
        throw new RuntimeException('Format penyimpanan tidak valid.');
    }
    return json_decode(substr($content, strlen(HPO_GUARD)), true, 64, JSON_THROW_ON_ERROR);
}

function write_private(string $file, array $data): void
{
    $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp.php';
    try {
        $content = HPO_GUARD . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Data tidak dapat ditulis.');
        }
        @chmod($temporary, 0640);
        if (!@rename($temporary, $file)) {
            throw new RuntimeException('Data tidak dapat disimpan.');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function defaults(string $publicRoot): array
{
    $script = file_get_contents($publicRoot . '/site-config.js');
    if ($script === false || !preg_match('/globalThis\.HPO_DEFAULT_CONFIG\s*=\s*(\{.*\})\s*;/s', $script, $match)) {
        throw new RuntimeException('Konfigurasi awal website tidak ditemukan.');
    }
    $config = json_decode($match[1], true, 64, JSON_THROW_ON_ERROR);
    $config['revision'] = 0;
    $config['updatedAt'] = null;
    return $config;
}

function verify_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $parts = parse_url($origin);
    if (!$parts || !isset($parts['host'], $parts['scheme']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
        fail_request(403, 'Origin permintaan tidak valid.');
    }
    $authority = strtolower($parts['host']);
    if (isset($parts['port']) && !(($parts['scheme'] === 'https' && $parts['port'] === 443) || ($parts['scheme'] === 'http' && $parts['port'] === 80))) {
        $authority .= ':' . $parts['port'];
    }
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    if (!hash_equals($host, $authority)) {
        fail_request(403, 'Permintaan dari situs lain tidak diizinkan.');
    }
}

function json_input(): array
{
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        fail_request(415, 'Gunakan format JSON.');
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 262144) {
        fail_request(413, 'Ukuran data terlalu besar.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 262145);
    if ($raw === false || strlen($raw) > 262144) {
        fail_request(413, 'Ukuran data terlalu besar.');
    }
    try {
        $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        fail_request(400, 'Data JSON tidak valid.');
    }
    if (!is_array($input)) {
        fail_request(400, 'Data JSON tidak valid.');
    }
    return $input;
}

function field_text(mixed $value, string $label, int $max = 200): string
{
    if (!is_string($value) || trim($value) === '' || (function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) > $max) {
        fail_request(400, $label . ' harus diisi, maksimal ' . $max . ' karakter.');
    }
    return trim($value);
}

function field_email(mixed $value, string $label): string
{
    $value = field_text($value, $label);
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        fail_request(400, $label . ' tidak valid.');
    }
    return $value;
}

function validate_password(mixed $password): string
{
    if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        fail_request(400, 'Gunakan password sepanjang 12–72 karakter.');
    }
    return $password;
}

function image_source(mixed $source, string $publicRoot): string
{
    if (!is_string($source) || !preg_match('~^assets/[a-zA-Z0-9_./-]+\.(?:jpg|jpeg|png|webp)$~i', $source) || str_contains($source, '..')) {
        fail_request(400, 'Lokasi gambar tidak valid.');
    }
    $resolved = realpath($publicRoot . '/' . $source);
    if ($resolved === false || !is_file($resolved) || !str_starts_with($resolved, $publicRoot . DIRECTORY_SEPARATOR)) {
        fail_request(400, 'File gambar tidak ditemukan.');
    }
    return $source;
}

function validate_site(array $input, array $default, string $publicRoot): array
{
    if (!is_array($input['contact'] ?? null) || !is_array($input['media'] ?? null) || !is_array($input['gallery'] ?? null)) {
        fail_request(400, 'Data website tidak lengkap.');
    }
    $contact = $input['contact'];
    $phone = field_text($contact['phone'] ?? null, 'Nomor telepon', 40);
    if (!preg_match('/^\+?[0-9 ()-]{7,40}$/', $phone) || strlen(preg_replace('/\D/', '', $phone)) < 8) {
        fail_request(400, 'Nomor telepon tidak valid.');
    }
    $whatsapp = is_string($contact['whatsapp'] ?? null) ? preg_replace('/[ +()\-]/', '', $contact['whatsapp']) : '';
    if (!preg_match('/^[1-9]\d{7,14}$/', $whatsapp)) {
        fail_request(400, 'Nomor WhatsApp harus menggunakan kode negara, contoh 6281703377400.');
    }
    $cleanContact = [
        'phone' => $phone,
        'whatsapp' => $whatsapp,
        'recruitmentEmail' => field_email($contact['recruitmentEmail'] ?? null, 'Email rekrutmen'),
        'businessEmail' => field_email($contact['businessEmail'] ?? null, 'Email bisnis'),
        'address' => field_text($contact['address'] ?? null, 'Alamat', 1000),
    ];
    $media = [];
    foreach (array_keys($default['media']) as $key) {
        $media[$key] = image_source($input['media'][$key] ?? null, $publicRoot);
    }
    if (!array_is_list($input['gallery']) || count($input['gallery']) < 1 || count($input['gallery']) > 40) {
        fail_request(400, 'Galeri harus berisi 1–40 foto.');
    }
    $gallery = [];
    foreach ($input['gallery'] as $photo) {
        if (!is_array($photo)) {
            fail_request(400, 'Data foto tidak valid.');
        }
        $gallery[] = [
            'src' => image_source($photo['src'] ?? null, $publicRoot),
            'en' => field_text($photo['en'] ?? null, 'Judul Inggris', 120),
            'id' => field_text($photo['id'] ?? null, 'Judul Indonesia', 120),
            'categoryEn' => field_text($photo['categoryEn'] ?? null, 'Kategori Inggris', 60),
            'categoryId' => field_text($photo['categoryId'] ?? null, 'Kategori Indonesia', 60),
        ];
    }
    return ['contact' => $cleanContact, 'media' => $media, 'gallery' => $gallery];
}

function start_admin_session(): void
{
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)HPO_SESSION_LIFETIME);
    session_name('hpo_session');
    $base = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php')));
    $cookiePath = $base === '/' || $base === '.' ? '/' : rtrim($base, '/') . '/';
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params(['lifetime' => HPO_SESSION_LIFETIME, 'path' => $cookiePath, 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    if (!session_start()) {
        throw new RuntimeException('Sesi admin tidak dapat dimulai.');
    }
}

function authenticated(?array $admin): bool
{
    return $admin !== null && isset($_SESSION['username'], $_SESSION['expires'], $_SESSION['version'])
        && $_SESSION['expires'] > time() && $_SESSION['username'] === $admin['username']
        && $_SESSION['version'] === $admin['version'];
}

function require_admin(?array $admin): void
{
    if (!authenticated($admin)) {
        fail_request(401, 'Silakan masuk ke panel admin.');
    }
}

function login_session(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION = ['username' => $admin['username'], 'version' => $admin['version'], 'expires' => time() + HPO_SESSION_LIFETIME];
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $route = $_GET['route'] ?? '';
    if (!is_string($route) || !preg_match('~^(site|contact|admin/(session|setup|login|logout|password|site|upload|enquiries))$~', $route)) {
        fail_request(404, 'Endpoint tidak ditemukan.');
    }
    if (!in_array($method, ['GET', 'POST'], true)) {
        fail_request(405, 'Metode tidak diizinkan.');
    }
    if ($method === 'POST') {
        verify_origin();
    }
    $publicRoot = realpath(__DIR__ . '/..');
    $dataRoot = getenv('HPO_DATA_DIR') ?: $publicRoot . '/.hpo-private';
    ensure_directory($dataRoot);
    $lockFile = $dataRoot . '/lock.php';
    $lock = fopen($lockFile, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Penyimpanan tidak dapat dikunci.');
    }
    if (filesize($lockFile) === 0) {
        fwrite($lock, HPO_GUARD);
        fflush($lock);
        @chmod($lockFile, 0640);
    }
    $default = defaults($publicRoot);
    $siteFile = $dataRoot . '/site.php';
    $adminFile = $dataRoot . '/admin.php';
    $keyFile = $dataRoot . '/setup-key.php';
    $site = read_private($siteFile, $default);
    $admin = read_private($adminFile);

    if ($route === 'site' && $method === 'GET') {
        respond(200, $site);
    }
    if ($route === 'contact') {
        if ($method !== 'POST') {
            fail_request(405, 'Metode tidak diizinkan.');
        }
        $input = json_input();
        if (!is_string($input['website'] ?? '') || trim($input['website'] ?? '') !== '') {
            fail_request(400, 'Formulir tidak valid.');
        }
        $name = field_text($input['name'] ?? null, 'Nama', 100);
        $email = field_email($input['email'] ?? null, 'Email');
        $message = field_text($input['message'] ?? null, 'Pesan', 5000);
        $interest = $input['interest'] ?? null;
        $topics = ['recruitment' => 'Seafarer / careers enquiry', 'partnership' => 'Crew request / business partnership', 'crew-management' => 'Crew management', 'training' => 'Training & development', 'payroll' => 'Payroll services', 'general' => 'General enquiry'];
        if (!is_string($interest) || !isset($topics[$interest]) || ($input['consent'] ?? null) !== true || preg_match('/[\r\n]/', $email)) {
            fail_request(400, 'Periksa kategori dan persetujuan penggunaan data.');
        }
        // Keep only a digest of the direct client IP for a short rate-limit window.
        $rateFile = $dataRoot . '/contact-limits.php';
        $rates = read_private($rateFile, []);
        $rates = array_filter($rates, fn(array $entry): bool => $entry['reset'] > time());
        $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $history = $rates[$ip] ?? ['count' => 0, 'reset' => time() + 900];
        if ($history['count'] >= 5) {
            header('Retry-After: ' . max(1, $history['reset'] - time()));
            fail_request(429, 'Terlalu banyak pesan. Silakan coba lagi nanti.');
        }
        $inboxFile = $dataRoot . '/enquiries.php';
        $enquiries = read_private($inboxFile, []);
        if (count($enquiries) >= 1000) {
            fail_request(503, 'Kotak masuk sedang penuh. Silakan hubungi tim melalui email.');
        }
        $id = bin2hex(random_bytes(12));
        $enquiries[] = ['id' => $id, 'name' => $name, 'email' => $email, 'interest' => $interest, 'message' => $message, 'createdAt' => gmdate('Y-m-d\TH:i:s\Z'), 'consent' => true];
        // A successful response means the message was persisted, independently of mail delivery.
        write_private($inboxFile, $enquiries);
        $history['count']++;
        $rates[$ip] = $history;
        write_private($rateFile, array_slice($rates, -2048, null, true));
        if (getenv('HPO_CONTACT_MAIL') === '1' && function_exists('mail')) {
            $recipient = $interest === 'recruitment' ? $site['contact']['recruitmentEmail'] : $site['contact']['businessEmail'];
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/', $recipient)) {
                $body = "New website enquiry\n\nName: {$name}\nEmail: {$email}\nTopic: {$topics[$interest]}\n\n{$message}\n\nReference: {$id}\nReply to the visitor's email address.";
                @mail($recipient, 'HPO website enquiry: ' . $topics[$interest], $body, ['Content-Type' => 'text/plain; charset=UTF-8', 'Reply-To' => $email]);
            }
        }
        respond(201, ['received' => true]);
    }
    start_admin_session();
    if ($route === 'admin/enquiries') {
        require_admin($admin);
        $inboxFile = $dataRoot . '/enquiries.php';
        $enquiries = read_private($inboxFile, []);
        if ($method === 'GET') {
            respond(200, ['enquiries' => array_reverse($enquiries)]);
        }
        $input = json_input();
        $id = field_text($input['deleteId'] ?? null, 'Referensi pesan', 24);
        $remaining = array_values(array_filter($enquiries, fn(array $entry): bool => $entry['id'] !== $id));
        if (count($remaining) === count($enquiries)) {
            fail_request(404, 'Pesan tidak ditemukan.');
        }
        write_private($inboxFile, $remaining);
        respond(200, ['ok' => true]);
    }
    if ($route === 'admin/session' && $method === 'GET') {
        if ($admin === null && !is_file($keyFile)) {
            write_private($keyFile, ['key' => bin2hex(random_bytes(24))]);
        }
        respond(200, ['setupRequired' => $admin === null, 'authenticated' => authenticated($admin), 'username' => authenticated($admin) ? $admin['username'] : null]);
    }
    if ($method !== 'POST') {
        fail_request(405, 'Metode tidak diizinkan.');
    }
    if ($route === 'admin/setup') {
        if ($admin !== null) {
            fail_request(409, 'Akun admin sudah dibuat. Silakan masuk.');
        }
        $input = json_input();
        $key = read_private($keyFile);
        if (!is_string($input['setupKey'] ?? null) || !$key || !hash_equals($key['key'], $input['setupKey'])) {
            fail_request(403, 'Kode pengaturan tidak cocok. Salin nilai key dari file .hpo-private/setup-key.php melalui File Manager hosting.');
        }
        $username = field_text($input['username'] ?? null, 'Username', 50);
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            fail_request(400, 'Username harus 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.');
        }
        $password = validate_password($input['password'] ?? null);
        $admin = ['username' => $username, 'passwordHash' => password_hash($password, PASSWORD_DEFAULT), 'version' => 1];
        write_private($adminFile, $admin);
        @unlink($keyFile);
        login_session($admin);
        respond(201, ['username' => $username]);
    }
    if ($route === 'admin/login') {
        if ($admin === null) {
            fail_request(409, 'Buat akun admin terlebih dahulu.');
        }
        $input = json_input();
        $attemptFile = $dataRoot . '/attempts.php';
        $attempts = read_private($attemptFile, []);
        $attempts = array_filter($attempts, fn(array $entry): bool => $entry['reset'] > time());
        $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $history = $attempts[$ip] ?? ['count' => 0, 'reset' => time() + 900];
        if ($history['count'] >= 8) {
            fail_request(429, 'Terlalu banyak percobaan. Coba lagi dalam 15 menit.');
        }
        $password = $input['password'] ?? null;
        if (($input['username'] ?? null) !== $admin['username'] || !is_string($password) || strlen($password) > 72 || !password_verify($password, $admin['passwordHash'])) {
            $history['count']++;
            $attempts[$ip] = $history;
            write_private($attemptFile, array_slice($attempts, -256, null, true));
            fail_request(401, 'Username atau password tidak cocok.');
        }
        unset($attempts[$ip]);
        write_private($attemptFile, $attempts);
        login_session($admin);
        respond(200, ['username' => $admin['username']]);
    }
    if ($route === 'admin/logout') {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        session_destroy();
        respond(200, ['ok' => true]);
    }
    require_admin($admin);
    if ($route === 'admin/password') {
        $input = json_input();
        if (!is_string($input['currentPassword'] ?? null) || strlen($input['currentPassword']) > 72 || !password_verify($input['currentPassword'], $admin['passwordHash'])) {
            fail_request(400, 'Password saat ini tidak cocok.');
        }
        $password = validate_password($input['newPassword'] ?? null);
        $admin['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
        $admin['version']++;
        write_private($adminFile, $admin);
        login_session($admin);
        respond(200, ['ok' => true]);
    }
    if ($route === 'admin/site') {
        $input = json_input();
        if (($input['revision'] ?? null) !== $site['revision']) {
            fail_request(409, 'Data sudah berubah di tab lain. Muat ulang panel sebelum mengedit lagi.');
        }
        $next = validate_site($input, $default, $publicRoot);
        $next['revision'] = $site['revision'] + 1;
        $next['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        write_private($siteFile, $next);
        respond(200, $next);
    }
    if ($route === 'admin/upload') {
        $file = $_FILES['image'] ?? null;
        if (!is_array($file)) {
            fail_request(413, 'Gambar tidak diterima. Pastikan ukuran unggahan di pengaturan PHP hosting mencukupi (upload_max_filesize 8M, post_max_size 10M).');
        }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > HPO_MAX_IMAGE) {
            fail_request(413, 'Ukuran gambar maksimal 8 MB. Periksa juga batas unggahan pada hosting.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            fail_request(400, 'Unggah gambar tidak berhasil. Silakan pilih ulang file.');
        }
        $image = @getimagesize($file['tmp_name']);
        $extensions = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!$image || !isset($extensions[$image[2]])) {
            fail_request(415, 'Gunakan file gambar JPG, PNG, atau WebP yang valid.');
        }
        $uploadRoot = $publicRoot . '/assets/uploads';
        ensure_directory($uploadRoot, 0755);
        $name = bin2hex(random_bytes(16)) . '.' . $extensions[$image[2]];
        if (!move_uploaded_file($file['tmp_name'], $uploadRoot . '/' . $name)) {
            throw new RuntimeException('Gambar tidak dapat disimpan.');
        }
        @chmod($uploadRoot . '/' . $name, 0644);
        respond(201, ['src' => 'assets/uploads/' . $name]);
    }
    fail_request(404, 'Endpoint tidak ditemukan.');
} catch (ApiError $error) {
    respond($error->status, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('HPO API: ' . $error->getMessage());
    respond(500, ['error' => 'Penyimpanan server bermasalah. Periksa versi PHP dan izin folder melalui panel hosting.']);
}
