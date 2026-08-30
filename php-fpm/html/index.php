<?php

/**
 * URL로부터 HTML/XML 콘텐츠를 안정적으로 가져오는 함수
 * @param string $url
 * @return string|null
 */
function fetchHtml(string $url): ?string
{
    $ch = curl_init();
    
    $parsedUrl = parse_url($url);
    $host = $parsedUrl['host'] ?? '';
    
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
        'Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Ch-Ua: "Not/A)Brand";v="8", "Chromium";v="126", "Google Chrome";v="126"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "Windows"',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
    ];

    if (!empty($host)) {
        $headers[] = 'Referer: https://' . $host . '/';
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_ENCODING, ''); // gzip, deflate 등 자동 압축 해제
    curl_setopt($ch, CURLOPT_COOKIEFILE, ''); // 인메모리 쿠키 엔진 활성화
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

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
    $articles = $xpath->query("//div[contains(@class, 'list_item') and not(contains(@class, 'notice')) and not(contains(@class, 'hongbo')) and not(contains(@class, 'list_top'))]");
    
    foreach ($articles as $article) {
        $viewsNode = $xpath->query(".//div[contains(@class, 'list_hit')]//span[contains(@class, 'hit')] | .//div[contains(@class, 'list_hit')]", $article);
        $views = $viewsNode->length > 0 ? trim($viewsNode->item(0)->textContent) : 'N/A';

        $categoryNode = $xpath->query(".//span[contains(@class, 'category')]", $article);
        $categoryPrefix = ($categoryNode->length > 0 && trim($categoryNode->item(0)->textContent) !== '')
            ? '[' . trim($categoryNode->item(0)->textContent) . '] '
            : '';

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

        $commentNode = $xpath->query(".//*[contains(@class, 'rSymph') or contains(@class, 'reply_symph')]", $article);
        $commentCount = '';
        if ($commentNode->length > 0) {
            $commentText = trim($commentNode->item(0)->textContent);
            $commentCount = preg_replace('/[^0-9]/', '', $commentText);
        }

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
                'category' => '',
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
 * 뽐뿌 PC 웹 게시판 파싱 함수
 * @param DOMXPath $xpath
 * @param string $boardUrl
 * @return array
 */
function parsePpomppu(DOMXPath $xpath, string $boardUrl): array
{
    $posts = [];
    $articles = $xpath->query("//table[contains(@id, 'revolution_main_table') or contains(@class, 'board_table')]//tr[contains(@class, 'baseList') and not(contains(@class, 'baseNotice')) and not(contains(@class, 'notice'))]");

    if ($articles->length === 0) {
        $articles = $xpath->query("//tr[contains(@class, 'baseList') and not(contains(@class, 'baseNotice')) and not(contains(@class, 'notice'))]");
    }

    foreach ($articles as $article) {
        $category = '';
        $title = '';
        $url = '';
        $commentCount = '';
        $views = 'N/A';

        // 1. 게시판명 / 카테고리 추출 (hot.php의 경우 zboard.php?id= 링크 또는 baseList-cat 태그)
        $catNode = $xpath->query('.//a[contains(@href, "zboard.php?id=") and not(contains(@href, "no=")) and not(contains(@href, "view.php"))] | .//span[contains(@class, "baseList-cat")]', $article)->item(0);
        if ($catNode) {
            $category = trim($catNode->textContent);
        }

        // 2. 실제 게시글 제목 및 링크 추출: 글 번호(no=) 또는 view.php / bbs_view 링크를 가진 a 태그
        $viewLinks = $xpath->query('.//a[contains(@href, "view.php") or contains(@href, "no=") or contains(@href, "bbs_view")]', $article);
        
        $selectedLinkNode = null;
        $bestTitle = '';

        foreach ($viewLinks as $aNode) {
            $href = trim($aNode->getAttribute('href'));
            if (empty($href) || strpos($href, 'javascript:') === 0) {
                continue;
            }

            // a 태그 내부에서 댓글수 span이나 img를 제외한 순수 텍스트 추출
            $text = '';
            foreach ($aNode->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $cls = $child->getAttribute('class');
                    if (strpos($cls, 'list_comment') !== false || strpos($cls, 'baseList-c') !== false || strpos($cls, 'comment') !== false) {
                        continue;
                    }
                    if ($child->nodeName === 'img') {
                        continue;
                    }
                    $text .= $child->textContent;
                } elseif ($child instanceof DOMText) {
                    $text .= $child->nodeValue;
                }
            }
            $text = trim($text);

            if (!empty($text)) {
                $selectedLinkNode = $aNode;
                $bestTitle = $text;
                break;
            } elseif ($selectedLinkNode === null) {
                $selectedLinkNode = $aNode;
            }
        }

        // viewLinks가 없을 경우 일반 a 태그 fallback (카테고리 링크 제외)
        if (!$selectedLinkNode) {
            $aNodes = $xpath->query('.//a[contains(@class, "baseList-title") or contains(@class, "title")]', $article);
            foreach ($aNodes as $aNode) {
                $href = trim($aNode->getAttribute('href'));
                if (!empty($category) && trim($aNode->textContent) === $category) {
                    continue;
                }
                $text = trim($aNode->textContent);
                if (!empty($text)) {
                    $selectedLinkNode = $aNode;
                    $bestTitle = $text;
                    break;
                }
            }
        }

        if ($selectedLinkNode) {
            $relativeUrl = trim($selectedLinkNode->getAttribute('href'));
            if (strpos($relativeUrl, 'http') === 0) {
                $url = $relativeUrl;
            } elseif (strpos($relativeUrl, '/') === 0) {
                $url = 'https://www.ppomppu.co.kr' . $relativeUrl;
            } else {
                $url = 'https://www.ppomppu.co.kr/zboard/' . $relativeUrl;
            }

            $title = $bestTitle;
            if (empty($title)) {
                $title = trim($selectedLinkNode->getAttribute('title')) ?: trim($selectedLinkNode->textContent);
            }
        }

        // 댓글 수 파싱
        $commentNode = $xpath->query('.//span[contains(@class, "list_comment2") or contains(@class, "baseList-c")] | .//font[contains(@class, "list_comment")]', $article);
        if ($commentNode->length > 0) {
            $cText = trim($commentNode->item(0)->textContent);
            if (preg_match('/\[?(\d{1,5})\]?/', $cText, $cm)) {
                $commentCount = $cm[1];
            }
        }
        
        // 제목 끝에 [숫자]가 포함되어 있는 경우 분리 정제
        if (preg_match('/\[(\d{1,5})\]\s*$/', $title, $m)) {
            if (empty($commentCount)) {
                $commentCount = $m[1];
            }
            $title = trim(preg_replace('/\[\d{1,5}\]\s*$/', '', $title));
        }

        // 조회수 / 추천수 파싱
        $viewsNode = $xpath->query('.//td[contains(@class, "baseList-views")] | .//td[contains(@class, "board_date")]/following-sibling::td[1]', $article);
        if ($viewsNode->length > 0) {
            $views = trim($viewsNode->item(0)->textContent);
        } else {
            $tds = $xpath->query('.//td', $article);
            if ($tds->length >= 5) {
                $candidate = trim($tds->item($tds->length - 2)->textContent);
                if (preg_match('/^\d+\s*-\s*\d+$/', $candidate) || is_numeric($candidate)) {
                    $views = $candidate;
                }
            }
        }

        if (!empty($title) && !empty($url)) {
            $posts[] = [
                'category' => $category,
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
 * 뽐뿌 모바일 웹 게시판 파싱 함수
 * @param DOMXPath $xpath
 * @param string $mobileUrl
 * @return array
 */
function parsePpomppuMobile(DOMXPath $xpath, string $mobileUrl): array
{
    $posts = [];
    $items = $xpath->query("//ul[contains(@class, 'bbsList') or contains(@class, 'list')]//li | //div[contains(@class, 'bbsList')]//li | //li[contains(@class, 'line')]");
    if ($items->length === 0) {
        $items = $xpath->query("//li[contains(@class, 'bbs')] | //ul//li");
    }

    foreach ($items as $item) {
        $category = '';
        $catNode = $xpath->query('.//span[contains(@class, "category") or contains(@class, "bbs_name") or contains(@class, "forum")]', $item)->item(0);
        if ($catNode) {
            $category = trim($catNode->textContent);
        }

        $aNodes = $xpath->query('.//a[contains(@href, "bbs_view") or contains(@href, "view.php") or contains(@class, "title")]', $item);
        
        $selectedLink = null;
        $title = '';

        foreach ($aNodes as $a) {
            $t = trim($a->textContent);
            if (!empty($category) && $t === $category) {
                continue;
            }
            if (!empty($t)) {
                $selectedLink = $a;
                $title = $t;
                break;
            } elseif ($selectedLink === null) {
                $selectedLink = $a;
            }
        }

        if (!$selectedLink) {
            continue;
        }

        $href = trim($selectedLink->getAttribute('href'));
        if (empty($href) || $href === '#') {
            continue;
        }

        if (strpos($href, 'http') === 0) {
            $url = $href;
        } elseif (strpos($href, '/') === 0) {
            $url = 'https://m.ppomppu.co.kr' . $href;
        } else {
            $url = 'https://m.ppomppu.co.kr/new/' . $href;
        }

        // PC URL로 변환
        if (preg_match('/id=([^&]+).*?no=(\d+)/', $url, $m)) {
            $url = "https://www.ppomppu.co.kr/zboard/view.php?id={$m[1]}&no={$m[2]}";
        }

        // 댓글 수 파싱
        $commentCount = '';
        $commentNode = $xpath->query('.//span[contains(@class, "hi") or contains(@class, "comment") or contains(@class, "re")]', $item);
        if ($commentNode->length > 0) {
            $cText = trim($commentNode->item(0)->textContent);
            if (preg_match('/(\d{1,5})/', $cText, $cm)) {
                $commentCount = $cm[1];
            }
        }
        
        if (preg_match('/\[(\d{1,5})\]\s*$/', $title, $m)) {
            if (empty($commentCount)) {
                $commentCount = $m[1];
            }
            $title = trim(preg_replace('/\[\d{1,5}\]\s*$/', '', $title));
        }

        // 조회수 파싱
        $viewsNode = $xpath->query('.//*[contains(@class, "ty") or contains(@class, "count") or contains(@class, "views") or contains(@class, "hit")]', $item);
        $views = $viewsNode->length > 0 ? trim($viewsNode->item(0)->textContent) : 'N/A';
        if ($views !== 'N/A' && preg_match('/(?:조회|hit|views)?\s*([0-9,kKmM.]+)/u', $views, $m)) {
            $views = $m[1];
        }

        if (!empty($title) && !empty($url)) {
            $posts[] = [
                'category' => $category,
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
 * 뽐뿌 RSS 피드 파싱 함수
 * @param string $rssXml
 * @return array
 */
function parsePpomppuRss(string $rssXml): array
{
    $posts = [];
    $xml = @simplexml_load_string($rssXml, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml && isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) {
            $rawTitle = (string)$item->title;
            $url = (string)$item->link;
            $category = isset($item->category) ? (string)$item->category : '';
            
            $commentCount = '';
            if (preg_match('/\[(\d+)\]\s*$/', $rawTitle, $matches)) {
                $commentCount = $matches[1];
                $rawTitle = trim(preg_replace('/\[\d+\]\s*$/', '', $rawTitle));
            }

            if (!empty($rawTitle) && !empty($url)) {
                $posts[] = [
                    'category' => $category,
                    'views' => 'N/A',
                    'comment_count' => $commentCount,
                    'title' => $rawTitle,
                    'url' => $url,
                ];
                if (count($posts) >= 20) {
                    break;
                }
            }
        }
    }
    return $posts;
}

/**
 * 뽐뿌 전용 수집기 (PC -> Mobile -> RSS 3단계 폴백)
 * @param string $boardUrl
 * @return array
 */
function getPostsForPpomppu(string $boardUrl): array
{
    // 1단계: PC 웹 크롤링
    $html = fetchHtml($boardUrl);
    if ($html !== null) {
        $dom = new DOMDocument();
        $utf8Html = @mb_convert_encoding($html, 'UTF-8', 'EUC-KR, CP949, UTF-8');
        if ($utf8Html === false) {
            $utf8Html = $html;
        }
        @$dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' . $utf8Html);
        $xpath = new DOMXPath($dom);
        $posts = parsePpomppu($xpath, $boardUrl);
        if (!empty($posts)) {
            return $posts;
        }
    }

    // 2단계: 모바일 웹 크롤링
    $mobileUrl = '';
    if (strpos($boardUrl, 'hot.php') !== false) {
        $mobileUrl = 'https://m.ppomppu.co.kr/new/hot_bbs.php';
    } else {
        $parsed = parse_url($boardUrl);
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
            if (isset($query['id'])) {
                $mobileUrl = 'https://m.ppomppu.co.kr/new/bbs_list.php?id=' . urlencode($query['id']);
            }
        }
    }

    if (!empty($mobileUrl)) {
        $mobileHtml = fetchHtml($mobileUrl);
        if ($mobileHtml !== null) {
            $dom = new DOMDocument();
            $utf8Html = @mb_convert_encoding($mobileHtml, 'UTF-8', 'EUC-KR, CP949, UTF-8');
            if ($utf8Html === false) {
                $utf8Html = $mobileHtml;
            }
            @$dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' . $utf8Html);
            $xpath = new DOMXPath($dom);
            $posts = parsePpomppuMobile($xpath, $mobileUrl);
            if (!empty($posts)) {
                return $posts;
            }
        }
    }

    // 3단계: RSS 피드 수집
    $rssId = '';
    if (strpos($boardUrl, 'hot.php') !== false) {
        $rssId = 'hot';
    } else {
        $parsed = parse_url($boardUrl);
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
            if (isset($query['id'])) {
                $rssId = $query['id'];
            }
        }
    }

    if (!empty($rssId)) {
        $rssUrl = 'https://www.ppomppu.co.kr/rss.php?id=' . urlencode($rssId);
        $rssXml = fetchHtml($rssUrl);
        if ($rssXml !== null) {
            $posts = parsePpomppuRss($rssXml);
            if (!empty($posts)) {
                return $posts;
            }
        }
    }

    return [];
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
                'category' => '',
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
    
    // 3. 각 게시판 URL에 접속하여 파싱
    foreach ($boards as $boardInfo) {
        $boardName = $boardInfo['name'];
        $boardUrl = $boardInfo['url'];
        
        $allPostsBySite[$siteName][$boardName] = [
            'url' => $boardUrl,
            'posts' => [],
        ];

        if ($siteName === '뽐뿌') {
            $allPostsBySite[$siteName][$boardName]['posts'] = getPostsForPpomppu($boardUrl);
        } else {
            $html = fetchHtml($boardUrl);
            if ($html !== null) {
                $dom = new DOMDocument();
                @$dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' . $html);
                $xpath = new DOMXPath($dom);

                if ($siteName === '클리앙') {
                    $allPostsBySite[$siteName][$boardName]['posts'] = parseClien($xpath);
                } elseif ($siteName === '보배드림') {
                    $allPostsBySite[$siteName][$boardName]['posts'] = parseBobaedream($xpath);
                }
            }
        }
    }
}

// 4. 최종 결과를 HTML 페이지로 출력
$currentTime = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="ko" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AllBoard - 커뮤니티 실시간 인기글 모음</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Pretendard:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-body: #f1f5f9;
            --bg-header: #ffffff;
            --bg-card: #ffffff;
            --bg-card-header: #f8fafc;
            --bg-hover: #f1f5f9;
            --bg-active-tab: #ffffff;
            --bg-inactive-tab: #e2e8f0;
            --bg-badge: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --text-visited: #94a3b8;
            --border-color: #e2e8f0;
            --border-subtle: #cbd5e1;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
            
            /* 커뮤니티 테마 색상 */
            --color-clien: #1e88e5;
            --color-clien-light: #e3f2fd;
            --color-ppom: #ea580c;
            --color-ppom-light: #ffedd5;
            --color-bobae: #0284c7;
            --color-bobae-light: #e0f2fe;
            --color-comment-hot: #ef4444;
            --color-comment-warm: #f97316;
            --color-comment-normal: #64748b;
        }

        [data-theme="dark"] {
            --bg-body: #0f172a;
            --bg-header: #1e293b;
            --bg-card: #1e293b;
            --bg-card-header: #182234;
            --bg-hover: #27354a;
            --bg-active-tab: #27354a;
            --bg-inactive-tab: #0f172a;
            --bg-badge: #334155;
            --text-primary: #f8fafc;
            --text-secondary: #cbd5e1;
            --text-muted: #64748b;
            --text-visited: #64748b;
            --border-color: #334155;
            --border-subtle: #475569;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5);

            --color-clien-light: rgba(30, 136, 229, 0.15);
            --color-ppom-light: rgba(234, 88, 12, 0.15);
            --color-bobae-light: rgba(2, 132, 199, 0.15);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Pretendard', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-primary);
            line-height: 1.5;
            transition: background-color 0.2s, color 0.2s;
            padding-bottom: 30px;
        }

        /* Top Navbar */
        .header {
            background-color: var(--bg-header);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }
        .header-content {
            max-width: 1920px;
            margin: 0 auto;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
            text-decoration: none;
            letter-spacing: -0.5px;
        }
        .brand-badge {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #ffffff;
            font-size: 0.75rem;
            padding: 2px 7px;
            border-radius: 6px;
            font-weight: 600;
        }
        .header-tools {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .update-time {
            font-size: 0.8rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .btn-tool {
            background-color: var(--bg-card-header);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }
        .btn-tool:hover {
            background-color: var(--bg-hover);
            color: var(--text-primary);
            border-color: var(--border-subtle);
        }
        .btn-tool.active {
            background-color: #3b82f6;
            color: #ffffff;
            border-color: #3b82f6;
        }

        /* 메인 레이아웃 (3컬럼 멀티 뷰) */
        .main-container {
            max-width: 1920px;
            margin: 16px auto 0;
            padding: 0 16px;
        }
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            align-items: start;
        }

        /* 커뮤니티 컬럼 카드 */
        .community-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .community-card:hover {
            box-shadow: var(--shadow-lg);
        }

        /* 커뮤니티별 상단 바 테마 */
        .community-card.site-clien { border-top: 4px solid var(--color-clien); }
        .community-card.site-ppom { border-top: 4px solid var(--color-ppom); }
        .community-card.site-bobae { border-top: 4px solid var(--color-bobae); }

        .community-card-header {
            padding: 12px 16px 8px;
            background-color: var(--bg-card-header);
            border-bottom: 1px solid var(--border-color);
        }
        .site-meta-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .site-name-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .site-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        .site-clien .site-dot { background-color: var(--color-clien); box-shadow: 0 0 6px rgba(30,136,229,0.5); }
        .site-ppom .site-dot { background-color: var(--color-ppom); box-shadow: 0 0 6px rgba(234,88,12,0.5); }
        .site-bobae .site-dot { background-color: var(--color-bobae); box-shadow: 0 0 6px rgba(2,132,199,0.5); }

        .site-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.3px;
        }
        .site-link-btn {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 6px;
            border-radius: 4px;
            background-color: var(--bg-badge);
            transition: all 0.15s;
        }
        .site-link-btn:hover {
            color: var(--text-primary);
        }

        /* 탭 버튼 그룹 */
        .tab-group {
            display: flex;
            gap: 6px;
            background-color: var(--bg-inactive-tab);
            padding: 3px;
            border-radius: 8px;
        }
        .tab-btn {
            flex: 1;
            padding: 6px 8px;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-size: 0.82rem;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: all 0.15s ease;
        }
        .tab-btn:hover {
            color: var(--text-primary);
        }
        .tab-btn.active {
            background-color: var(--bg-active-tab);
            color: var(--text-primary);
            box-shadow: var(--shadow-sm);
        }
        .site-clien .tab-btn.active { color: var(--color-clien); }
        .site-ppom .tab-btn.active { color: var(--color-ppom); }
        .site-bobae .tab-btn.active { color: var(--color-bobae); }

        /* 게시물 리스트 테이블 */
        .board-panel {
            display: none;
        }
        .board-panel.active {
            display: block;
        }
        .posts-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .post-row {
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.12s;
            height: 38px;
        }
        .post-row:last-child {
            border-bottom: none;
        }
        .post-row:hover {
            background-color: var(--bg-hover);
        }

        .col-title {
            padding: 6px 10px;
            vertical-align: middle;
            overflow: hidden;
        }
        .title-container {
            display: flex;
            align-items: center;
            gap: 6px;
            overflow: hidden;
            white-space: nowrap;
        }
        .post-rank {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            min-width: 18px;
            text-align: center;
            display: inline-block;
        }
        .post-rank.top3 {
            color: #f59e0b;
        }
        
        .category-tag {
            font-size: 0.72rem;
            padding: 1px 5px;
            border-radius: 4px;
            background-color: var(--bg-badge);
            color: var(--text-secondary);
            font-weight: 500;
            white-space: nowrap;
            flex-shrink: 0;
            max-width: 65px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .post-link {
            color: var(--text-primary);
            text-decoration: none;
            font-size: 0.86rem;
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex-grow: 1;
        }
        .post-link:hover {
            text-decoration: underline;
            color: #2563eb;
        }
        .post-row.visited .post-link {
            color: var(--text-visited) !important;
        }
        .post-row.visited .post-rank {
            opacity: 0.6;
        }

        /* 댓글수 뱃지 */
        .comment-badge {
            font-size: 0.74rem;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 10px;
            white-space: nowrap;
            flex-shrink: 0;
            line-height: 1.2;
            margin-left: 2px;
        }
        .comment-badge.hot {
            background-color: #fee2e2;
            color: #dc2626;
        }
        .comment-badge.warm {
            background-color: #ffedd5;
            color: #ea580c;
        }
        .comment-badge.normal {
            background-color: var(--bg-badge);
            color: var(--color-comment-normal);
        }
        [data-theme="dark"] .comment-badge.hot {
            background-color: rgba(220, 38, 38, 0.25);
            color: #f87171;
        }
        [data-theme="dark"] .comment-badge.warm {
            background-color: rgba(234, 88, 12, 0.25);
            color: #fb923c;
        }

        /* 조회수 열 */
        .col-views {
            width: 68px;
            padding: 6px 10px 6px 4px;
            text-align: right;
            font-size: 0.78rem;
            color: var(--text-muted);
            white-space: nowrap;
            vertical-align: middle;
            font-variant-numeric: tabular-nums;
        }

        /* 티어 아이콘 */
        .tier-icon {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
            vertical-align: middle;
        }
        .tier-1 { fill: #a855f7; filter: drop-shadow(0 0 2px rgba(168,85,247,0.5)); } /* 보라 */
        .tier-2 { fill: #ef4444; filter: drop-shadow(0 0 2px rgba(239,68,68,0.5)); } /* 빨강 */
        .tier-3 { fill: #f97316; } /* 주황 */
        .tier-4 { fill: #10b981; } /* 초록 */

        .empty-posts {
            padding: 30px 16px;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        /* 반응형 모바일/태블릿 */
        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .header-content {
                padding: 10px 16px;
            }
        }

        /* 모드 전환: 전체 펼쳐보기 모드 */
        .expand-mode .tab-group {
            display: none;
        }
        .expand-mode .board-panel {
            display: block !important;
            border-bottom: 8px solid var(--bg-body);
        }
        .expand-mode .board-panel-title {
            display: block;
            padding: 8px 16px;
            background-color: var(--bg-inactive-tab);
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        .board-panel-title {
            display: none;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="index.php" class="brand">
                <span>AllBoard</span>
                <span class="brand-badge">3대 커뮤니티</span>
            </a>

            <div class="header-tools">
                <div class="update-time" title="데이터 갱신 시각">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span><?php echo $currentTime; ?></span>
                </div>

                <button id="btn-refresh" class="btn-tool" onclick="location.reload();" title="새로고침">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"></path><path d="M1 20v-6h6"></path><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                    <span>새로고침</span>
                </button>

                <button id="btn-view-mode" class="btn-tool" onclick="toggleViewMode();" title="탭 모드 / 전체 펼쳐보기 전환">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    <span id="view-mode-text">모두 펼치기</span>
                </button>

                <button id="btn-theme" class="btn-tool" onclick="toggleTheme();" title="다크/라이트 모드 전환">
                    <span id="theme-icon">🌙</span>
                    <span id="theme-text">다크모드</span>
                </button>
            </div>
        </div>
    </header>

    <main class="main-container">
        <div class="dashboard-grid" id="dashboard-grid">
            <?php 
            $siteThemeMap = [
                '클리앙' => ['class' => 'site-clien', 'url' => 'https://www.clien.net'],
                '뽐뿌' => ['class' => 'site-ppom', 'url' => 'https://www.ppomppu.co.kr'],
                '보배드림' => ['class' => 'site-bobae', 'url' => 'https://www.bobaedream.co.kr']
            ];
            $siteIndex = 0;
            ?>

            <?php foreach ($allPostsBySite as $siteName => $boards): ?>
                <?php 
                    $siteMeta = $siteThemeMap[$siteName] ?? ['class' => '', 'url' => '#'];
                    $boardNames = array_keys($boards);
                ?>
                <section class="community-card <?php echo $siteMeta['class']; ?>" id="site-card-<?php echo $siteIndex; ?>">
                    <div class="community-card-header">
                        <div class="site-meta-bar">
                            <div class="site-name-wrapper">
                                <span class="site-dot"></span>
                                <h2 class="site-title"><?php echo htmlspecialchars($siteName); ?></h2>
                            </div>
                            <a href="<?php echo htmlspecialchars($siteMeta['url']); ?>" target="_blank" rel="noopener noreferrer" class="site-link-btn" title="커뮤니티 바로가기">
                                <span>방문</span>
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                            </a>
                        </div>

                        <!-- 탭 네비게이션 버튼 -->
                        <div class="tab-group" role="tablist">
                            <?php $bIdx = 0; foreach ($boardNames as $bName): ?>
                                <button 
                                    class="tab-btn <?php echo $bIdx === 0 ? 'active' : ''; ?>" 
                                    data-site="<?php echo $siteIndex; ?>" 
                                    data-target="board-<?php echo $siteIndex; ?>-<?php echo $bIdx; ?>" 
                                    onclick="switchTab(<?php echo $siteIndex; ?>, <?php echo $bIdx; ?>);"
                                    title="<?php echo htmlspecialchars($bName); ?>">
                                    <?php echo htmlspecialchars($bName); ?>
                                </button>
                            <?php $bIdx++; endforeach; ?>
                        </div>
                    </div>

                    <!-- 각 탭별 게시판 패널 -->
                    <div class="posts-container">
                        <?php $bIdx = 0; foreach ($boards as $boardName => $boardData): ?>
                            <?php 
                                $boardUrl = is_array($boardData) && isset($boardData['url']) ? $boardData['url'] : '';
                                $posts = is_array($boardData) && isset($boardData['posts']) ? $boardData['posts'] : (is_array($boardData) ? $boardData : []);
                            ?>
                            <div class="board-panel <?php echo $bIdx === 0 ? 'active' : ''; ?>" id="board-<?php echo $siteIndex; ?>-<?php echo $bIdx; ?>">
                                <div class="board-panel-title">
                                    <?php echo htmlspecialchars($boardName); ?>
                                    <?php if (!empty($boardUrl)): ?>
                                        <a href="<?php echo htmlspecialchars($boardUrl); ?>" target="_blank" rel="noopener noreferrer" style="color: inherit; font-size: 0.75rem; margin-left: 4px;">↗</a>
                                    <?php endif; ?>
                                </div>

                                <?php if (empty($posts)): ?>
                                    <div class="empty-posts">게시물을 불러올 수 없거나 목록이 비어 있습니다.</div>
                                <?php else: ?>
                                    <table class="posts-table">
                                        <tbody>
                                            <?php $rank = 1; foreach ($posts as $post): ?>
                                                <?php 
                                                    $commentNum = (int)($post['comment_count'] ?? 0);
                                                    $commentClass = 'normal';
                                                    if ($commentNum >= 30) {
                                                        $commentClass = 'hot';
                                                    } elseif ($commentNum >= 10) {
                                                        $commentClass = 'warm';
                                                    }
                                                    $postHash = md5($post['url']);
                                                ?>
                                                <tr class="post-row" data-post-id="<?php echo $postHash; ?>">
                                                    <td class="col-title">
                                                        <div class="title-container">
                                                            <span class="post-rank <?php echo $rank <= 3 ? 'top3' : ''; ?>"><?php echo $rank; ?></span>
                                                            
                                                            <?php if (!empty($post['category'])): ?>
                                                                <span class="category-tag" title="<?php echo htmlspecialchars($post['category']); ?>">
                                                                    <?php echo htmlspecialchars($post['category']); ?>
                                                                </span>
                                                            <?php endif; ?>

                                                            <?php echo getTierIconHtml($post['views']); ?>

                                                            <a href="<?php echo htmlspecialchars($post['url']); ?>" 
                                                               target="_blank" 
                                                               rel="noopener noreferrer" 
                                                               class="post-link" 
                                                               title="<?php echo htmlspecialchars($post['title']); ?>"
                                                               onclick="markAsVisited('<?php echo $postHash; ?>');">
                                                                <?php echo htmlspecialchars($post['title']); ?>
                                                            </a>

                                                            <?php if (!empty($post['comment_count'])): ?>
                                                                <span class="comment-badge <?php echo $commentClass; ?>">
                                                                    <?php echo htmlspecialchars($post['comment_count']); ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="col-views" title="조회수/추천수">
                                                        <?php echo htmlspecialchars($post['views']); ?>
                                                    </td>
                                                </tr>
                                            <?php $rank++; endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        <?php $bIdx++; endforeach; ?>
                    </div>
                </section>
                <?php $siteIndex++; ?>
            <?php endforeach; ?>
        </div>
    </main>

    <script>
        // 1. 탭 전환 기능
        function switchTab(siteIndex, boardIndex) {
            const card = document.getElementById('site-card-' + siteIndex);
            if (!card) return;

            // 탭 버튼 active 클래스 처리
            const tabButtons = card.querySelectorAll('.tab-btn');
            tabButtons.forEach((btn, idx) => {
                if (idx === boardIndex) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            // 게시판 패널 표시/숨김
            const panels = card.querySelectorAll('.board-panel');
            panels.forEach((panel, idx) => {
                if (idx === boardIndex) {
                    panel.classList.add('active');
                } else {
                    panel.classList.remove('active');
                }
            });

            // 사용자 탭 선택 상태 저장 (사이트별)
            try {
                localStorage.setItem('tab_site_' + siteIndex, boardIndex);
            } catch (e) {}
        }

        // 2. 다크모드 관리
        function initTheme() {
            const savedTheme = localStorage.getItem('theme') || 
                (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            setTheme(savedTheme);
        }

        function setTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            const icon = document.getElementById('theme-icon');
            const text = document.getElementById('theme-text');
            if (theme === 'dark') {
                icon.textContent = '☀️';
                text.textContent = '라이트모드';
            } else {
                icon.textContent = '🌙';
                text.textContent = '다크모드';
            }
            try {
                localStorage.setItem('theme', theme);
            } catch (e) {}
        }

        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            setTheme(newTheme);
        }

        // 3. 뷰 모드 관리 (3단 탭 뷰 vs 모두 펼치기)
        function toggleViewMode() {
            const container = document.getElementById('dashboard-grid');
            const btnText = document.getElementById('view-mode-text');
            const isExpand = container.classList.toggle('expand-mode');

            if (isExpand) {
                btnText.textContent = '탭 모드로 보기';
                try { localStorage.setItem('view_mode', 'expand'); } catch (e) {}
            } else {
                btnText.textContent = '모두 펼치기';
                try { localStorage.setItem('view_mode', 'tab'); } catch (e) {}
            }
        }

        function initViewMode() {
            try {
                const savedMode = localStorage.getItem('view_mode');
                if (savedMode === 'expand') {
                    document.getElementById('dashboard-grid').classList.add('expand-mode');
                    document.getElementById('view-mode-text').textContent = '탭 모드로 보기';
                }
            } catch (e) {}
        }

        // 4. 읽은 글 (Visited) 기억 및 스타일 적용
        function markAsVisited(postId) {
            try {
                let visited = JSON.parse(localStorage.getItem('visited_posts') || '[]');
                if (!visited.includes(postId)) {
                    visited.push(postId);
                    if (visited.length > 500) visited.shift(); // 최대 500개 유지
                    localStorage.setItem('visited_posts', JSON.stringify(visited));
                }
                const row = document.querySelector(`tr[data-post-id="${postId}"]`);
                if (row) row.classList.add('visited');
            } catch (e) {}
        }

        function applyVisitedStyles() {
            try {
                const visited = JSON.parse(localStorage.getItem('visited_posts') || '[]');
                if (visited.length > 0) {
                    visited.forEach(postId => {
                        const row = document.querySelector(`tr[data-post-id="${postId}"]`);
                        if (row) row.classList.add('visited');
                    });
                }
            } catch (e) {}
        }

        // 5. 이전 탭 위치 복원
        function restoreTabs() {
            const cards = document.querySelectorAll('.community-card');
            cards.forEach((card, siteIndex) => {
                try {
                    const savedTabIndex = localStorage.getItem('tab_site_' + siteIndex);
                    if (savedTabIndex !== null) {
                        switchTab(siteIndex, parseInt(savedTabIndex, 10));
                    }
                } catch (e) {}
            });
        }

        // 초기화 실행
        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            initViewMode();
            restoreTabs();
            applyVisitedStyles();
        });
    </script>
</body>
</html>
<?php
?>