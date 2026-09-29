<?php
session_start();

// ==========================================
// 1. 관리자 계정 및 API 인증키 설정
// ==========================================
define('ADMIN_ID', 'admin');         // 관리자 아이디
define('ADMIN_PW', 'baroin1800!@#');        // 관리자 비밀번호 (반드시 수정 후 사용)

define('INC_CLIENT_KEY', 'xOTIiLCJpcCI6IjExMi4xNzUuMTg0Ljg0IiwidGltZXN0YW1wIjoiMTc5MDA1OTg0OTg3NyJ9.b0G17bYu2A9II2k5RqCoS8zoTi3qREIOxI2wyq01its');
define('INC_SECRET_KEY', 'PjOFg2vJ4lQymmjfx06QM4y6aXujrAT6'); 

// ==========================================
// 2. 로그아웃 처리
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['is_admin']);
    session_destroy();
    header('Location: consult.php');
    exit;
}

// ==========================================
// 3. 로그인 처리
// ==========================================
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $inputId = trim($_POST['admin_id'] ?? '');
    $inputPw = trim($_POST['admin_pw'] ?? '');

    if ($inputId === ADMIN_ID && $inputPw === ADMIN_PW) {
        $_SESSION['is_admin'] = true;
        header('Location: consult.php');
        exit;
    } else {
        $loginError = '아이디 또는 비밀번호가 일치하지 않습니다.';
    }
}

$isLoggedIn = !empty($_SESSION['is_admin']);

// ==========================================
// 4. AES-256-GCM 복호화 함수
// ==========================================
function decryptIncruitData($encBase64, $secretKey) {
    $raw = base64_decode($encBase64);
    if (!$raw || strlen($raw) < 28) {
        return null;
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, -16);
    $cipherText = substr($raw, 12, -16);

    $decrypted = openssl_decrypt(
        $cipherText,
        'aes-256-gcm',
        $secretKey,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return $decrypted ? json_decode($decrypted, true) : null;
}

// ==========================================
// 5. 요청 파라미터 파싱 및 API 호출 (명세 4.1~4.3 반영)
// ==========================================
$allowedReqTypes = [
    '01' => '01: 패스트트랙 무료상담',
    '02' => '02: 1:1 맞춤상담',
    '03' => '03: 패스트트랙 구매내역'
];

// 요청 구분 코드 (기본값: 01)
$req_type = $_GET['req_type'] ?? '01';
if (!array_key_exists($req_type, $allowedReqTypes)) {
    $req_type = '01';
}

// 날짜 파라미터 (YYYY-MM-DD 입력을 API 규격 YYYYMMDD로 변환)
$raw_start_dt = $_GET['search_start_dt'] ?? date('Y-m-d', strtotime('-30 days'));
$raw_close_dt = $_GET['search_close_dt'] ?? date('Y-m-d');

$search_start_dt = str_replace('-', '', $raw_start_dt);
$search_close_dt = str_replace('-', '', $raw_close_dt);

// 페이징 파라미터
$page = max(1, (int)($_GET['page'] ?? 1));
$page_size = max(10, min(100, (int)($_GET['page_size'] ?? 100)));

$listData = [];
$totalCount = 0;
$totalPage = 1;
$currentPage = $page;
$errorMsg = '';

if ($isLoggedIn) {
    // API GET 요청 URL 생성
    $params = [
        'req_type'        => $req_type,
        'search_start_dt' => $search_start_dt,
        'search_close_dt' => $search_close_dt,
        'page'            => $page,
        'page_size'       => $page_size
    ];

    $apiUrl = "https://cpapi.incruit.com/coaching/consult/list?" . http_build_query($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'INC_CP_CLIENTKEY: ' . trim(INC_CLIENT_KEY)
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $resJson = json_decode($response, true);
        if (isset($resJson['responseHeader']['status']) && $resJson['responseHeader']['status'] === 0) {
            $resBody     = $resJson['response'] ?? [];
            $totalCount  = $resBody['totalCount'] ?? 0;
            $totalPage   = $resBody['totalPage'] ?? 1;
            $currentPage = $resBody['currentPage'] ?? $page;

            $encData = $resBody['data'] ?? '';
            if (!empty($encData)) {
                $listData = decryptIncruitData($encData, trim(INC_SECRET_KEY));
            }
        } else {
            $errorMsg = $resJson['responseHeader']['message'] ?? 'API 응답 오류가 발생했습니다.';
        }
    } else {
        $errorMsg = "API 통신 실패 (HTTP 상태 코드: {$httpCode})";
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>상담신청 내역 관리자</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 30px 20px; background-color: #f4f6f9; color: #333; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .header-bar { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }
        .header-bar h2 { margin: 0; font-size: 22px; color: #111; }
        .btn-logout { background-color: #6c757d; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 5px; font-size: 14px; font-weight: bold; }
        .btn-logout:hover { background-color: #5a6268; }
        
        /* 검색 필터 바 */
        .filter-card { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; padding: 18px; margin-bottom: 20px; }
        .filter-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        .form-item { display: flex; flex-direction: column; gap: 5px; }
        .form-item label { font-size: 12px; font-weight: bold; color: #495057; }
        .form-item input, .form-item select { padding: 8px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; background: #fff; }
        .btn-search { margin-top: auto; padding: 9px 20px; background-color: #007bff; color: white; border: none; border-radius: 4px; font-size: 14px; font-weight: bold; cursor: pointer; }
        .btn-search:hover { background-color: #0056b3; }

        /* 상태 요약 바 */
        .info-bar { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; color: #666; }
        .info-bar strong { color: #007bff; }

        /* 테이블 스타일 */
        table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        th, td { border: 1px solid #e9ecef; padding: 12px; text-align: left; font-size: 14px; }
        th { background-color: #f1f3f5; color: #495057; font-weight: 600; }
        tr:nth-child(even) { background-color: #fdfdfd; }
        
        /* 페이징 버튼 */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 25px; }
        .page-link { padding: 8px 14px; border: 1px solid #dee2e6; background: #fff; border-radius: 4px; text-decoration: none; color: #007bff; font-size: 14px; font-weight: bold; }
        .page-link.disabled { color: #6c757d; pointer-events: none; background: #e9ecef; border-color: #dee2e6; }
        
        /* 로그인 화면 */
        .login-box { max-width: 360px; margin: 60px auto; padding: 30px; background: #fff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .login-box h3 { margin-top: 0; text-align: center; margin-bottom: 20px; color: #2c3e50; }
        .btn-login { width: 100%; padding: 12px; background-color: #007bff; color: white; border: none; border-radius: 4px; font-size: 16px; font-weight: bold; cursor: pointer; }
        .error { color: #d9534f; background: #fdf7f7; padding: 12px; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
    </style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
    <!-- 1. 로그인 폼 -->
    <div class="login-box">
        <h3>관리자 로그인</h3>
        <?php if ($loginError): ?>
            <div class="error"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>
        <form method="POST" action="consult.php">
            <input type="hidden" name="login_submit" value="1">
            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:5px; font-weight:bold; font-size:14px;">아이디</label>
                <input type="text" name="admin_id" required autofocus style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:5px; font-weight:bold; font-size:14px;">비밀번호</label>
                <input type="password" name="admin_pw" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
            </div>
            <button type="submit" class="btn-login">로그인</button>
        </form>
    </div>

<?php else: ?>
    <!-- 2. 메인 내역 조회 화면 -->
    <div class="container">
        <div class="header-bar">
            <h2>상담신청 내역 조회 시스템</h2>
            <a href="consult.php?action=logout" class="btn-logout">로그아웃</a>
        </div>

        <!-- 파라미터 검색 조건 폼 (GET 방식) -->
        <div class="filter-card">
            <form method="GET" action="consult.php" class="filter-form">
                <div class="form-item">
                    <label for="req_type">요청 구분</label>
                    <select name="req_type" id="req_type">
                        <?php foreach ($allowedReqTypes as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $req_type === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-item">
                    <label for="search_start_dt">시작일</label>
                    <input type="date" name="search_start_dt" id="search_start_dt" value="<?= htmlspecialchars($raw_start_dt) ?>">
                </div>

                <div class="form-item">
                    <label for="search_close_dt">종료일</label>
                    <input type="date" name="search_close_dt" id="search_close_dt" value="<?= htmlspecialchars($raw_close_dt) ?>">
                </div>

                <div class="form-item">
                    <label for="page_size">페이지 당 건수</label>
                    <select name="page_size" id="page_size">
                        <option value="10" <?= $page_size === 10 ? 'selected' : '' ?>>10개씩</option>
                        <option value="30" <?= $page_size === 30 ? 'selected' : '' ?>>30개씩</option>
                        <option value="50" <?= $page_size === 50 ? 'selected' : '' ?>>50개씩</option>
                        <option value="100" <?= $page_size === 100 ? 'selected' : '' ?>>100개씩 (기본)</option>
                    </select>
                </div>

                <button type="submit" class="btn-search">검색 적용</button>
            </form>
        </div>

        <?php if ($errorMsg): ?>
            <div class="error">⚠️ Error: <?= htmlspecialchars($errorMsg) ?></div>
        <?php else: ?>
            <div class="info-bar">
                <span>검색 유형: <strong><?= htmlspecialchars($allowedReqTypes[$req_type]) ?></strong> | 총 <strong><?= number_format($totalCount) ?></strong>건</span>
                <span>페이지: <strong><?= $currentPage ?></strong> / <?= $totalPage ?> Page</span>
            </div>

            <?php if (empty($listData)): ?>
                <p style="text-align: center; color: #666; padding: 40px 0; background:#fff; border:1px solid #eee; border-radius:4px;">조회된 데이터가 없습니다.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 5%;">No</th>
                            <th style="width: 15%;">신청자명</th>
                            <th style="width: 18%;">연락처</th>
                            <th style="width: 22%;">이메일</th>
                            <th style="width: 18%;">최종학력</th>
                            <th style="width: 22%;">희망 산업 및 직무</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listData as $idx => $row): ?>
                        <tr>
                            <td><?= (($currentPage - 1) * $page_size) + $idx + 1 ?></td>
                            <td><?= htmlspecialchars($row['userNm'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['userMobileNo'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['userEmail'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['finalEdu'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['hopeJobIndustry'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- 페이징 처리 영역 -->
                <?php if ($totalPage > 1): ?>
                    <?php
                    $prevPage = max(1, $currentPage - 1);
                    $nextPage = min($totalPage, $currentPage + 1);
                    $queryParams = [
                        'req_type'        => $req_type,
                        'search_start_dt' => $raw_start_dt,
                        'search_close_dt' => $raw_close_dt,
                        'page_size'       => $page_size
                    ];
                    $prevUrl = 'consult.php?' . http_build_query(array_merge($queryParams, ['page' => $prevPage]));
                    $nextUrl = 'consult.php?' . http_build_query(array_merge($queryParams, ['page' => $nextPage]));
                    ?>
                    <div class="pagination">
                        <a href="<?= $prevUrl ?>" class="page-link <?= $currentPage <= 1 ? 'disabled' : '' ?>">◀ 이전페이지</a>
                        <span><strong><?= $currentPage ?></strong> / <?= $totalPage ?></span>
                        <a href="<?= $nextUrl ?>" class="page-link <?= $currentPage >= $totalPage ? 'disabled' : '' ?>">다음페이지 ▶</a>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

</body>
</html>