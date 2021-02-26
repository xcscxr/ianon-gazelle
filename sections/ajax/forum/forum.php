<?php

/**********|| Page to show individual forums || ********************************\

Things to expect in $_GET:
    ForumID: ID of the forum curently being browsed
    page:    The page the user's on.
    page = 1 is the same as no page

********************************************************************************/

//---------- Things to sort out before it can start printing/generating content

// Check for lame SQL injection attempts
$forum = (new Gazelle\Manager\Forum)->findById((int)$_GET['forumid']);
if (is_null($forum)) {
    print json_die(['status' => 'failure']);
}

$user = new Gazelle\User($LoggedUser['ID']);
if (!$user->readAccess($forum)) {
    json_die("failure", "insufficient permission");
}

if (isset($_GET['pp'])) {
    $PerPage = (int) $_GET['pp'];
} elseif (isset($LoggedUser['PostsPerPage'])) {
    $PerPage = $LoggedUser['PostsPerPage'];
} else {
    $PerPage = POSTS_PER_PAGE;
}
[$Page, $Limit] = Format::page_limit(TOPICS_PER_PAGE);
$Pages = Format::get_pages($Page, $forum->numThreads(), TOPICS_PER_PAGE, 9);
$ForumID = $forum->id();
$ForumName = $forum->name();
$threadList = $forum->tableOfContentsForum($Page);

if (!count($threadList)) {
    print json_die([
        'status'    => 'success',
        'forumName' => $ForumName,
        'threads'   => []
    ]);
}

// forums_last_read_topics is a record of the last post a user read in a topic, and what page that was on
$args = array_keys($threadList);
$DB->prepared_query("
    SELECT l.TopicID,
        l.PostID,
        ceil((SELECT count(*) FROM forums_posts AS p WHERE p.TopicID = l.TopicID AND p.ID <= l.PostID) / ?) AS Page
    FROM forums_last_read_topics AS l
    WHERE l.UserID = ?
        AND l.TopicID IN (" . placeholders($args) . ")
    ", $PerPage, $LoggedUser['ID'], ...$args
);
$LastRead = $DB->to_array('TopicID');

$JsonTopics = [];
foreach ($threadList as $thread) {
    [$threadId, $Title, $AuthorID, $Locked, $Sticky, $PostCount, $LastID, $LastTime, $LastAuthorID] = array_values($thread);

    // handle read/unread posts - the reason we can't cache the whole page
    $unread = (!$Locked || $Sticky)
        && (
            (empty($LastRead[$threadId]) || $LastRead[$threadId]['PostID'] < $LastID)
            && strtotime($LastTime) > $user->forumCatchupEpoch()
        );

    $UserInfo = Users::user_info($AuthorID);
    $AuthorName = $UserInfo['Username'];
    $UserInfo = Users::user_info($LastAuthorID);
    $LastAuthorName = $UserInfo['Username'];
    $JsonTopics[] = [
        'topicId'        => $threadId,
        'title'          => display_str($Title),
        'authorId'       => $AuthorID,
        'authorName'     => $AuthorName,
        'locked'         => $Locked == 1,
        'sticky'         => $Sticky == 1,
        'postCount'      => $PostCount,
        'lastID'         => $LastID ?? 0,
        'lastTime'       => $LastTime,
        'lastAuthorId'   => $LastAuthorID ?? 0,
        'lastAuthorName' => $LastAuthorName ?? '',
        'lastReadPage'   => (int)($LastRead[$threadId]['Page'] ?? 0),
        'lastReadPostId' => (int)($LastRead[$threadId]['PostID'] ?? 0),
        'read'           => !$unread,
    ];
}

print json_encode([
    'status' => 'success',
    'response' => [
        'forumName'   => $ForumName,
        'currentPage' => $Page,
        'pages'       => (int)ceil($forum->numThreads() / TOPICS_PER_PAGE),
        'threads'     => $JsonTopics
    ]
]);
