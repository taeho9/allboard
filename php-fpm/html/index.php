<?php

// JSON 파일 경로
$jsonFile = 'boards.json';

// 최종 결과를 담을 빈 배열 초기화
$allPostsBySite = [];

// 1. JSON 파일 읽기 및 파싱
if (!file_exists($jsonFile)) {
    die("에러: {$jsonFile} 파일을 찾을 수 없습니다.");
}

$jsonContent = file_get_contents($jsonFile);
// JSON을 연관 배열로 변환
$communities = json_decode($jsonContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("에러: JSON 파일 형식이 올바라지 않습니다.");
}


// 2. 각 커뮤니티 사이트별로 반복
foreach ($communities as $siteName => $boards) {
    // echo "========= [{$siteName}] 사이트 처리 시작 =========\n";
    // error_log("========= [{$siteName}] 사이트 처리 시작 =========");
    // 결과 배열에 사이트 이름을 키로 하는 빈 배열을 초기화
    $allPostsBySite[$siteName] = [];

    // 3. 각 게시판 URL에 접속하여 HTML 파싱 (기존 로직)
    foreach ($boards as $boardInfo) {
        $boardName = $boardInfo['name'];
        $boardUrl = $boardInfo['url'];
        
        // 사이트 배열 아래에 게시판 이름으로 된 빈 배열을 초기화합니다.
        $allPostsBySite[$siteName][$boardName] = [];

        // cURL을 사용하여 URL로부터 HTML을 가져옵니다.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $boardUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode != 200 || $html === false) {
            // echo "경고: '{$boardName}' 게시판의 HTML을 가져오는 데 실패했습니다. (HTTP 상태 코드: {$httpCode})\n";
            // error_log("경고: '{$boardName}' 게시판의 HTML을 가져오는 데 실패했습니다. (HTTP 상태 코드: {$httpCode})");
            continue; // 다음 게시판으로 넘어감
        }

        // DOMDocument를 사용하여 HTML 파싱
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);

        $articles = $xpath->query("//div[contains(@class, 'list_item') and contains(@class, 'symph_row')]");

        foreach ($articles as $article) {
            $viewsNode = $xpath->query(".//div[@class='list_hit']/span[@class='hit']", $article);
            $views = $viewsNode->length > 0 ? trim($viewsNode->item(0)->textContent) : 'N/A';

            $boardNode = $xpath->query(".//span[contains(@class, 'shortname')]", $article);
            $currentBoard = $boardNode->length > 0 ? trim($boardNode->item(0)->getAttribute('title')) : 'N/A';
            
            $titleNode = $xpath->query(".//span[contains(@class, 'subject_fixed')]", $article);
            $title = $titleNode->length > 0 ? trim($titleNode->item(0)->getAttribute('title')) : 'N/A';

            // 댓글 수 추출 (예: <span class="rSymph05">23</span>)
            $commentNode = $xpath->query(".//span[contains(@class, 'rSymph')]", $article);
            $commentCount = $commentNode->length > 0 ? trim($commentNode->item(0)->textContent) : '';

            $urlNode = $xpath->query(".//a[contains(@class, 'list_subject')]", $article);
            $url = 'N/A';
            if ($urlNode->length > 0) {
                $relativeUrl = $urlNode->item(0)->getAttribute('href');
                $url = 'https://www.clien.net' . $relativeUrl;
            }

            // 추출한 정보를 현재 처리 중인 사이트의 결과 배열에 추가
            if ($title !== 'N/A') {
                // 이제 게시판 이름 키 아래에 게시물을 추가합니다.
                $allPostsBySite[$siteName][$boardName][] = [
                    'views' => $views,
                    'comment_count' => $commentCount,
                    'title' => $title,
                    'url' => $url,
                ];
            }
        }
    }
}

// 4. 최종 결과를 HTML 페이지로 출력
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>커뮤니티 인기글 모음</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; background-color: #f4f4f9; color: #333; }
        .container { max-width: 900px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #2c3e50; }
        .site-section { margin-bottom: 40px; }
        .site-title { font-size: 2em; color: #34495e; border-bottom: 2px solid #3498db; padding-bottom: 10px; margin-bottom: 20px; }
        .board-title { font-size: 1.5em; color: #2980b9; margin-top: 20px; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #ecf0f1; }
        tr:hover { background-color: #f5f5f5; }
        td.views { text-align: center; width: 80px; }
        a { color: #3498db; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .comment-count { color: #e74c3c; font-weight: bold; margin-left: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>커뮤니티 인기글 모음</h1>

        <?php foreach ($allPostsBySite as $siteName => $boards): ?>
            <section class="site-section">
                <h2 class="site-title"><?php echo htmlspecialchars($siteName); ?></h2>

                <?php foreach ($boards as $boardName => $posts): ?>
                    <h3 class="board-title"><?php echo htmlspecialchars($boardName); ?></h3>
                    <?php if (empty($posts)): ?>
                        <p>게시물이 없습니다.</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>제목</th>
                                    <th class="views">조회수</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($posts as $post): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo htmlspecialchars($post['url']); ?>" target="_blank"><?php echo htmlspecialchars($post['title']); ?></a>
                                            <?php if (!empty($post['comment_count'])): ?>
                                                <span class="comment-count">[<?php echo htmlspecialchars($post['comment_count']); ?>]</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="views"><?php echo htmlspecialchars($post['views']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </div>
</body>
</html>

?>