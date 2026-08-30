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
        section { margin-top: 40px; }
        .site-title-container { display: flex; align-items: center; justify-content: space-between; background-color: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 5px solid #3498db; }
        .site-title { font-size: 2em; color: #34495e; margin: 0; flex-grow: 1; }
        .login-btn, .logout-btn { font-size: 0.6em; vertical-align: middle; margin-left: 10px; padding: 5px 10px; border: 1px solid #ccc; background-color: #f0f0f0; color: #333; text-decoration: none; border-radius: 4px; cursor: pointer; }
        .logout-btn { background-color: #e74c3c; color: white; border-color: #c0392b; }
        .modal { display: none; position: fixed; z-index: 1001; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 400px; border-radius: 8px; }
        .close-btn { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .boards-container { display: flex; flex-wrap: wrap; gap: 20px; }
        .board-column { flex: 1; min-width: 320px; }
        .board-title { font-size: 1.3em; color: #1a1a1a; margin-top: 20px; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 8px 8px; text-align: left; border-bottom: 1px solid #ddd; font-size: 0.9em; }
        th { background-color: #ecf0f1; font-weight: normal; }
        td { word-break: break-all; }
        tr:hover { background-color: #f5f5f5; }
        td.views { text-align: center; width: 75px; font-size: 0.85em; }
        td.category-col { width: 85px; text-align: center; }
        .category-badge { display: inline-block; padding: 2px 6px; font-size: 0.8em; font-weight: 500; background-color: #e8f4fd; color: #2980b9; border-radius: 4px; border: 1px solid #d4e6f1; white-space: nowrap; max-width: 80px; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
        a { color: #000000; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .comment-count { color: #e74c3c; font-weight: bold; margin-left: 5px; font-size: 0.85em; }
        .float-nav { position: fixed; top: 50%; right: 20px; transform: translateY(-50%); display: flex; flex-direction: column; gap: 10px; z-index: 1000; }
        .nav-btn { width: 50px; height: 50px; background-color: #34495e; color: white; border: none; border-radius: 50%; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.2); transition: background-color 0.3s; }
        .nav-btn:hover { background-color: #2c3e50; }
        @media (max-width: 768px) {
            body { padding: 5px; }
            .container { padding: 10px 5px; }
            th, td { padding: 6px 2px; font-size: 0.85em; }
            td.category-col { width: 65px; }
            .category-badge { max-width: 60px; font-size: 0.75em; padding: 1px 3px; }
            td.views { width: 60px; }
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
                            
                            // 카테고리(게시판명)가 존재하는지 확인
                            $hasCategory = false;
                            foreach ($posts as $p) {
                                if (!empty($p['category'])) {
                                    $hasCategory = true;
                                    break;
                                }
                            }
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
                                            <?php if ($hasCategory): ?>
                                                <th style="width: 22%;">게시판</th>
                                                <th style="width: 58%;">제목</th>
                                            <?php else: ?>
                                                <th style="width: 75%;">제목</th>
                                            <?php endif; ?>
                                            <th class="views">조회수</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($posts as $post): ?>
                                            <tr>
                                                <?php if ($hasCategory): ?>
                                                    <td class="category-col">
                                                        <?php if (!empty($post['category'])): ?>
                                                            <span class="category-badge"><?php echo htmlspecialchars($post['category']); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
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