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
                    'title' => $title,
                    'url' => $url,
                ];
            }
        }
    }
}

// 4. 최종 결과 JSON 형식으로 출력
header('Content-Type: application/json; charset=utf-8');
echo json_encode($allPostsBySite, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

?>