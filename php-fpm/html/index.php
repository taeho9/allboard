<?php

/**
 * URL로부터 HTML 콘텐츠를 안정적으로 가져오는 함수
 * @param string $url
 * @return string|null
 */
function fetchHtml(string $url): ?string
{
    $ch = curl_init();
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Upgrade-Insecure-Requests: 1',
    ];

    $parsedUrl = parse_url($url);
    if (isset($parsedUrl['scheme']) && isset($parsedUrl['host'])) {
        $headers[] = 'Referer: ' . $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/';
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_ENCODING, ''); // gzip, deflate 자동 처리
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 400 && $html !== false && !empty($html)) {
        return $html;
    }
    return null;
}

/**
 * 클리앙 게시판 파싱 함수
 * @param DOMXPath $xpath
 * @return array
 */
function parseClien(DOMXPath $xpath): array
{
    $posts = [];
    // 일반 게시글 목록 선택 (공지 및 홍보 제외)
    $articles = $xpath->query("//div[contains(@class, 'list_item') and not(contains(@class, 'notice')) and not(contains(@class, 'hongbo')) and not(contains(@class, 'list_top'))]");
    
    foreach ($articles as $article) {
        // 조회수 파싱
        $viewsNode = $xpath->query(".//div[contains(@class, 'list_hit')]//span[contains(@class, 'hit')] | .//div[contains(@class, 'list_hit')]", $article);
        $views = $viewsNode->length > 0 ? trim($viewsNode->item(0)->textContent) : 'N/A';

        // 카테고리 (분류)가 있는 경우
        $categoryNode = $xpath->query(".//span[contains(@class, 'category')]", $article);
        $categoryPrefix = ($categoryNode->length > 0 && trim($categoryNode->item(0)->textContent) !== '')
            ? '[' . trim($categoryNode->item(0)->textContent) . '] '
            : '';

        // 제목 노드 파싱
        $titleNode = $xpath->query(".//span[contains(@class, 'subject_fixed')]", $article);
        if ($titleNode->length === 0) {
            $titleNode = $xpath->query(".//a[contains(@class, 'list_subject')]", $article);
        }

        $title = 'N/A';
        if ($titleNode->length > 0) {
            $rawTitle = trim($titleNode->item(0)->getAttribute('title'));
            if (empty($rawTitle)) {
                $rawTitle = trim($titleNode->item(0)->textContent);
            }
            if (!empty($rawTitle)) {
                $title = $categoryPrefix . $rawTitle;
            }
        }

        // 댓글수 파싱
        $commentNode = $xpath->query(".//*[contains(@class, 'rSymph') or contains(@class, 'reply_symph')]", $article);
        $commentCount = '';
        if ($commentNode->length > 0) {
            $commentText = trim($commentNode->item(0)->textContent);
            $commentCount = preg_replace('/[^0-9]/', '', $commentText);
        }

        // 링크 URL 파싱
        $urlNode = $xpath->query(".//a[contains(@class, 'list_subject')]", $article);
        $url = 'N/A';
        if ($urlNode->length > 0) {
            $relativeUrl = trim($urlNode->item(0)->getAttribute('href'));
            if (strpos($relativeUrl, 'http') === 0) {
                $url = $relativeUrl;
            } else {
                $url = 'https://www.clien.net' . (strpos($relativeUrl, '/') === 0 ? '' : '/') . $relativeUrl;
            }
        }

        if ($title !== 'N/A' && $url !== 'N/A') {
            $posts[] = [
                'views' => $views,
                'comment_count' => $commentCount,
                'title' => $title,
                'url' => $url,
            ];
            if (count($posts) >= 20) {
                break;
            }
        }
    }
    return $posts;
}

/**
 * 뽐뿌 게시판 파싱 함수 (HOT게시판 및 일반게시판 통합 지원)
 * @param DOMXPath $xpath
 * @param string $boardUrl
 * @return array
 */
function parsePpomppu(DOMXPath $xpath, string $boardUrl): array
{
    $posts = [];
    $isHotBoard = (strpos($boardUrl, 'hot.php') !== false);

    // 게시글 목록 tr 쿼리 (공지 제외)
    $articleQuery = "//table[contains(@id, 'revolution_main_table') or contains(@class, 'board_table')]//tr[contains(@class, 'baseList') and not(contains(@class, 'baseNotice')) and not(contains(@class, 'notice'))]";
    $articles = $xpath->query($articleQuery);

    if ($articles->length === 0) {
        // 테이블 클래스/id가 다른 경우의 fallback
        $articles = $xpath->query("//tr[contains(@class, 'baseList') and not(contains(@class, 'baseNotice')) and not(contains(@class, 'notice'))]");
    }

    foreach ($articles as $article) {
        $title = 'N/A';
        $url = 'N/A';
        $commentCount = '';
        $views = 'N/A';

        // 1. 링크 및 제목 노드 찾기
        $linkNode = $xpath->query('.//a[contains(@class, "baseList-title")] | .//a[contains(@href, "view.php")]', $article)->item(0);
        if ($linkNode) {
            $relativeUrl = trim($linkNode->getAttribute('href'));
            if (strpos($relativeUrl, 'http') === 0) {
                $url = $relativeUrl;
            } elseif (strpos($relativeUrl, '/') === 0) {
                $url = 'https://www.ppomppu.co.kr' . $relativeUrl;
            } else {
                $url = 'https://www.ppomppu.co.kr/zboard/' . $relativeUrl;
            }

            // 제목 텍스트 정제 (댓글수 span 태그나 img 등 분리)
            $titleParts = '';
            foreach ($linkNode->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $cls = $child->getAttribute('class');
                    // 댓글수 표시 영역 제외
                    if (strpos($cls, 'list_comment') !== false || strpos($cls, 'baseList-c') !== false) {
                        continue;
                    }
                    if ($child->nodeName === 'img') {
                        continue;
                    }
                    $titleParts .= $child->textContent;
                } elseif ($child instanceof DOMText) {
                    $titleParts .= $child->nodeValue;
                }
            }
            $title = trim($titleParts);
            if (empty($title)) {
                $title = trim($linkNode->textContent);
            }
        }

        // 2. 댓글 수 파싱
        $commentNode = $xpath->query('.//*[contains(@class, "list_comment") or contains(@class, "baseList-c")]', $article);
        if ($commentNode->length > 0) {
            $commentText = trim($commentNode->item(0)->textContent);
            $commentCount = preg_replace('/[^0-9]/', '', $commentText);
        }

        // 3. 조회수 파싱
        $viewsNode = $xpath->query('.//td[contains(@class, "baseList-views")] | .//td[contains(@class, "board_date")]/following-sibling::td[1]', $article);
        if ($viewsNode->length > 0) {
            $views = trim($viewsNode->item(0)->textContent);
        }

        if ($title !== 'N/A' && $url !== 'N/A') {
            $posts[] = [
                'views' => $views,
                'comment_count' => $commentCount,
                'title' => $title,
                'url' => $url,
            ];
            if (count($posts) >= 20) {
                break;
            }
        }
    }
    return $posts;
}

/**
 * 보배드림 게시판 파싱 함수
 * @param DOMXPath $xpath
 * @return array
 */
function parseBobaedream(DOMXPath $xpath): array
{
    $posts = [];
    $articles = $xpath->query("//table[contains(@class, 'clistTable02') or contains(@class, 'board_list')]//tbody//tr");
    if ($articles->length === 0) {
        $articles = $xpath->query("//table[contains(@class, 'clistTable02') or contains(@class, 'board_list')]//tr");
    }

    foreach ($articles as $article) {
        $titleNode = $xpath->query(".//a[contains(@class, 'bsubject')]", $article);
        if ($titleNode->length === 0) {
            continue;
        }

        $linkElement = $titleNode->item(0);
        $relativeUrl = trim($linkElement->getAttribute('href'));
        if (strpos($relativeUrl, 'http') === 0) {
            $url = $relativeUrl;
        } else {
            $url = 'https://www.bobaedream.co.kr' . (strpos($relativeUrl, '/') === 0 ? '' : '/') . $relativeUrl;
        }

        $commentCount = '';
        $commentNode = $xpath->query(".//*[contains(@class, 'tot_reply') or contains(@class, 'totreply')]", $article);
        if ($commentNode->length > 0) {
            $commentCount = trim($commentNode->item(0)->textContent);
            $commentCount = preg_replace('/[^0-9]/', '', $commentCount);
        }

        $title = '';
        foreach ($linkElement->childNodes as $child) {
            if ($child instanceof DOMElement && (strpos($child->getAttribute('class'), 'tot_reply') !== false || strpos($child->getAttribute('class'), 'totreply') !== false)) {
                continue;
            }
            $title .= $child->textContent;
        }
        $title = trim($title);

        $viewsNode = $xpath->query(".//td[contains(@class, 'count')]", $article);
        $views = $viewsNode->length > 0 ? trim($viewsNode->item(0)->textContent) : 'N/A';

        if (!empty($title)) {
            $posts[] = [
                'views' => $views,
                'comment_count' => $commentCount,
                'title' => $title,
                'url' => $url,
            ];
            if (count($posts) >= 20) {
                break;
            }
        }
    }
    return $posts;
}

/**
 * 조회수에 따른 등급 아이콘 HTML 생성 함수
 * @param string $viewsStr
 * @return string
 */
function getTierIconHtml(string $viewsStr): string
{
    $viewsStr = trim($viewsStr);
    $isVote = false;
    $views = 0;

    // 추천수 형식 확인 (예: "40 - 0")
    if (strpos($viewsStr, '-') !== false) {
        $parts = explode('-', $viewsStr);
        $views = (int)preg_replace('/[^0-9]/', '', $parts[0]);
        $isVote = true;
    } else {
        $lowerStr = strtolower($viewsStr);
        // 백만 단위 (M) 처리 (예: "3.5 M")
        if (strpos($lowerStr, 'm') !== false) {
            $numberPart = (float)preg_replace('/[^0-9.]/', '', $lowerStr);
            $views = (int)($numberPart * 1000000);
        } elseif (strpos($lowerStr, 'k') !== false) {
            // 천 단위 (k) 처리 (예: "11.1 k")
            $numberPart = (float)preg_replace('/[^0-9.]/', '', $lowerStr);
            $views = (int)($numberPart * 1000);
        } elseif (strpos($viewsStr, '.') !== false) {
            // 'k'가 없으나 소수점이 있는 경우 (예: "21.3")
            $numberPart = (float)preg_replace('/[^0-9.]/', '', $viewsStr);
            $views = (int)($numberPart * 1000);
        } else {
            $views = (int)preg_replace('/[^0-9]/', '', $viewsStr);
        }
    }
    
    $class = '';
    $title = '';
    
    if ($isVote) {
        if ($views >= 200) {
            $class = 'tier-1';
            $title = 'SuperHit (200+)';
        } elseif ($views >= 100) {
            $class = 'tier-2';
            $title = 'BigHit (100+)';
        } elseif ($views >= 50) {
            $class = 'tier-3';
            $title = 'Hit! (50+)';
        } elseif ($views >= 10) {
            $class = 'tier-4';
            $title = 'Cool! (10+)';
        }
    } else {
        if ($views >= 20000) {
            $class = 'tier-1';
            $title = 'SuperHit (20,000+)';
        } elseif ($views >= 10000) {
            $class = 'tier-2';
            $title = 'BigHit (10,000+)';
        } elseif ($views >= 6000) {
            $class = 'tier-3';
            $title = 'Hit! (6,000+)';
        } elseif ($views >= 3000) {
            $class = 'tier-4';
            $title = 'Cool! (3,000+)';
        }
    }

    if ($class === '') {
        return '';
    }

    return sprintf(
        '<svg class="tier-icon %s" viewBox="0 0 24 24" title="%s"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>',
        $class,
        $title
    );
}

// --- 메인 로직 시작 ---
$jsonFile = 'boards.json';

// 최종 결과를 담을 빈 배열 초기화
$allPostsBySite = [];

// 1. JSON 파일 읽기 및 파싱
if (!file_exists($jsonFile)) {
    die("에러: {$jsonFile} 파일을 찾을 수 없습니다.");
}

$jsonContent = file_get_contents($jsonFile);
$communities = json_decode($jsonContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("에러: JSON 파일 형식이 올바라지 않습니다.");
}

// 2. 각 커뮤니티 사이트별로 반복
foreach ($communities as $siteName => $boards) {
    $allPostsBySite[$siteName] = [];
    
    // 3. 각 게시판 URL에 접속하여 HTML 파싱
    foreach ($boards as $boardInfo) {
        $boardName = $boardInfo['name'];
        $boardUrl = $boardInfo['url'];
        
        $allPostsBySite[$siteName][$boardName] = [
            'url' => $boardUrl,
            'posts' => [],
        ];

        $html = fetchHtml($boardUrl);

        if ($html === null) {
            continue; // 가져오기 실패 시 다음 게시판으로 진행
        }

        // DOMDocument를 사용하여 HTML 파싱
        $dom = new DOMDocument();
        // 뽐뿌는 EUC-KR 인코딩을 사용하므로 UTF-8로 변환
        if ($siteName === '뽐뿌') {
            $html = mb_convert_encoding($html, 'UTF-8', 'EUC-KR, UTF-8, CP949');
            $html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
        }
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);

        if ($siteName === '클리앙') {
            $allPostsBySite[$siteName][$boardName]['posts'] = parseClien($xpath);
        } elseif ($siteName === '뽐뿌') {
            $allPostsBySite[$siteName][$boardName]['posts'] = parsePpomppu($xpath, $boardUrl);
        } elseif ($siteName === '보배드림') {
            $allPostsBySite[$siteName][$boardName]['posts'] = parseBobaedream($xpath);
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
    <title>AllBoard - 커뮤니티 인기글 모음</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; background-color: #f4f4f9; color: #333; }
        .container { max-width: 1440px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #2c3e50; }
        section { margin-top: 40px; } /* 사이트 섹션 간의 상단 여백 추가 */
        .site-title-container { display: flex; align-items: center; justify-content: space-between; background-color: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 5px solid #3498db; }
        .site-title { font-size: 2em; color: #34495e; margin: 0; flex-grow: 1; }
        .login-btn, .logout-btn { font-size: 0.6em; vertical-align: middle; margin-left: 10px; padding: 5px 10px; border: 1px solid #ccc; background-color: #f0f0f0; color: #333; text-decoration: none; border-radius: 4px; cursor: pointer; }
        .logout-btn { background-color: #e74c3c; color: white; border-color: #c0392b; }
        /* 로그인 모달 스타일 */
        .modal { display: none; position: fixed; z-index: 1001; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 400px; border-radius: 8px; }
        .close-btn { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .boards-container { display: flex; flex-wrap: wrap; gap: 20px; }
        .board-column { flex: 1; min-width: 280px; }
    .board-title { font-size: 1.5em; color: #1a1a1a; margin-top: 20px; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #ddd; font-size: 0.9em; } /* 행간 여백과 폰트 크기 조정 */
        th { background-color: #ecf0f1; font-weight: normal; }
        td { word-break: break-all; } /* 긴 제목이 셀을 넘어가지 않도록 처리 */
        tr:hover { background-color: #f5f5f5; }
        td.views { text-align: center; width: 80px; }
    a { color: #000000; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .comment-count { color: #e74c3c; font-weight: bold; margin-left: 5px; }
        .float-nav { position: fixed; top: 50%; right: 20px; transform: translateY(-50%); display: flex; flex-direction: column; gap: 10px; z-index: 1000; }
        .nav-btn { width: 50px; height: 50px; background-color: #34495e; color: white; border: none; border-radius: 50%; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.2); transition: background-color 0.3s; }
        .nav-btn:hover { background-color: #2c3e50; }
        @media (max-width: 768px) {
            body { padding: 5px; }
            .container { padding: 10px 5px; }
            th, td { padding: 8px 2px; }
        }
        /* 등급 아이콘 스타일 */
        .tier-icon { width: 15px; height: 15px; vertical-align: text-bottom; margin-right: 4px; }
        .tier-1 { fill: #9b59b6; } /* 1등급: 보라색 */
        .tier-2 { fill: #e74c3c; } /* 2등급: 빨간색 */
        .tier-3 { fill: #e67e22; } /* 3등급: 주황색 */
        .tier-4 { fill: #2ecc71; } /* 4등급: 초록색 */
    </style>
</head>
<body>
    <div class="container">
        <h1>AllBoard - 커뮤니티 인기글 모음</h1>

        <?php $siteIndex = 0; ?>
        <?php foreach ($allPostsBySite as $siteName => $boards): ?>
            <section id="site-<?php echo $siteIndex; ?>">
                <div class="site-title-container">
                    <h2 class="site-title"><?php echo htmlspecialchars($siteName); ?></h2>
                </div>

                <div class="boards-container">
                    <?php foreach ($boards as $boardName => $boardData): ?>
                        <?php 
                            $boardUrl = is_array($boardData) && isset($boardData['url']) ? $boardData['url'] : '';
                            $posts = is_array($boardData) && isset($boardData['posts']) ? $boardData['posts'] : (is_array($boardData) ? $boardData : []);
                        ?>
                        <div class="board-column">
                            <h3 class="board-title">
                                <?php if (!empty($boardUrl)): ?>
                                    <a href="<?php echo htmlspecialchars($boardUrl); ?>" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: none;">
                                        <?php echo htmlspecialchars($boardName); ?> ↗
                                    </a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($boardName); ?>
                                <?php endif; ?>
                            </h3>
                            <?php if (empty($posts)): ?>
                                <p style="color: #888; font-size: 0.9em; padding: 10px 0;">게시물을 불러올 수 없거나 목록이 비어 있습니다.</p>
                            <?php else: ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th style="width: 75%;">제목</th>
                                            <th class="views">조회수</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($posts as $post): ?>
                                            <tr>
                                                <td>
                                                    <?php echo getTierIconHtml($post['views']); ?>
                                                    <a href="<?php echo htmlspecialchars($post['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($post['title']); ?></a>
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
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php $siteIndex++; ?>
        <?php endforeach; ?>
    </div>

    <div class="float-nav">
        <button id="nav-up" class="nav-btn">▲</button>
        <button id="nav-down" class="nav-btn">▼</button>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const sections = document.querySelectorAll('section');
            const navUp = document.getElementById('nav-up');
            const navDown = document.getElementById('nav-down');
            let currentSectionIndex = 0;

            function scrollToSection(index) {
                if (index >= 0 && index < sections.length) {
                    sections[index].scrollIntoView({ behavior: 'smooth' });
                    currentSectionIndex = index;
                }
            }

            function findCurrentSection() {
                let closestSectionIndex = 0;
                let minDistance = Number.MAX_VALUE;

                sections.forEach((section, index) => {
                    const distance = Math.abs(section.getBoundingClientRect().top);
                    if (distance < minDistance) {
                        minDistance = distance;
                        closestSectionIndex = index;
                    }
                });
                return closestSectionIndex;
            }

            navUp.addEventListener('click', () => {
                let currentIdx = findCurrentSection();
                scrollToSection(currentIdx - 1);
            });

            navDown.addEventListener('click', () => {
                let currentIdx = findCurrentSection();
                scrollToSection(currentIdx + 1);
            });
        });
    </script>
</body>
</html>

?>