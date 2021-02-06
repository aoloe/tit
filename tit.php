<?php
/*
 *      Tinier Issue Tracker (TITter) v1.0
 *      SQLite based, single file Issue Tracker
 *
 *      Copyright 2021 Ale Rimoldi <ale at graphicslab dot org>
 *      Copyright 2010-2013 Jwalanta Shrestha <jwalanta at gmail dot com>
 *      GNU GPL
 */

// error_reporting(E_ALL);
// ini_set('display_errors', '1');

// polyfill from https://github.com/symfony/polyfill-php80
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return 0 === strncmp($haystack, $needle, \strlen($needle));
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return '' === $needle || ('' !== $haystack && 0 === substr_compare($haystack, $needle, -\strlen($needle)));
    }
}

// Configs: if they already exist, add them to the existing ones
$config = (isset($config) ? $config : []) + [
    'title' => "My Project", // Project Title
    'email' => "noreply@example.com", // "From" email address for notifications

    'db_file' => "tit.db", // File containing the sqlite DB (will be created if missing)

    // Send notifications for specific events
    'notify_issue_create' => false,
    'notify_issue_edit' => false,
    'notify_issue_delete' => false,
    'notify_issue_status' => false, // issue status change (solved / unsolved)
    'notify_issue_priority' => false,
    'notify_comment_create' => false,
];

// List of initial users.
// Mandatory fields: username, password (hash)
// Optional fields: email, admin (true/false)
if (!isset($users)) {
    $users = [
        ['username' => 'admin', 'password' => password_hash('admin', PASSWORD_DEFAULT), 'email' => 'admin@example.com', 'admin' => true],
        ['username' => 'user', 'password' => password_hash('user', PASSWORD_DEFAULT),'email' => 'user@example.com'],
    ];
}

// List of statuses.
if (!isset($statuses)) {
    $statuses = array(0 => "Active", 1 => "Resolved");
}

function get_base_url() {
    // print('server:<pre>'.print_r($_SERVER, true).'</pre>');
    $protocol = 'http'.(substr($_SERVER['SERVER_PROTOCOL'], 4, 1) === 'S' ? 's' : '').'://';
    $base_dir = substr(dirname($_SERVER['SCRIPT_FILENAME']), strlen($_SERVER['DOCUMENT_ROOT']));
    $port = $_SERVER['SERVER_PORT'] === '80' ? '' : ':'.$_SERVER['SERVER_PORT'];
    $file = $_SERVER['SCRIPT_NAME'] === '/index.php' ? '' : $_SERVER['SCRIPT_NAME'];
    return $protocol.$_SERVER['SERVER_NAME'].$port.$base_dir.$file;
}

$base_url = get_base_url();

class TinyTemplate {
    var $vars = [];
    var $d = ['\{\{ ', ' }}'];

    static public function factory() {
        return new TinyTemplate();
    }

    public function set_delimiter($start, $end) {
        $this->d = [$start, $end];
        return $this;
    }

    public function add($names, $value) {
        if (is_array($names) && is_array($value)) {
            foreach ($names as $name) {
                $this->vars[$name] = $value[$name];
            }
        } else {
            if (!is_array($names)) {$names = [$names];}
            foreach ($names as $name) {
                $this->vars[$name] = $value;
            }
        }
        return $this;
    }

    public function fetch($html, $next_variable = null) {
        $result = preg_replace_callback(
            "/{$this->d[0]}([a-z_-]+)(\|raw)?{$this->d[1]}/",
            fn($m) =>  array_key_exists($m[1], $this->vars) ? (count($m) == 3 ? $this->vars[$m[1]] : htmlentities($this->vars[$m[1]])) : '',
            $html);
        if (is_null($next_variable)) {
            return $result;
        }
        $this->vars = [$next_variable => $result];
        return $this;
    }
}

$base_html_template = <<<'EOD'
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>{{ title }}</title>
    {{ head|raw }}
    <style>
    {{ style|raw }}
    </style>
  </head>
  <body>
    <div id="container">
      <h1>{{ title }}</h1>
      {{ body|raw }}
    </div>
  </body>
</html>
EOD;


// Here we go...
session_start();

try {
    $db = new PDO('sqlite:'.$config['db_file']);
} catch (PDOException $e) {
    die('DB Connection failed: '.$e->getMessage());
}

// Authentication

$db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, password TEXT NOT NULL, email TEXT, admin INTEGER NOT NULL)');
if ($db->query('select count(*) from users')->fetchColumn() == 0) {
    $stmt = $db->prepare('INSERT INTO users (username, password, email, admin) values (:u, :p, :e, :a)');
    foreach ($users as $u) {
        $stmt->execute([':u' => $u['username'], ':p' => $u['password'], ':e' => $u['email'] ?? null, ':a' => $u['admin'] ?? false]);
    }
}

$login_error = '';
$user = null;

if (array_key_exists('logout', $_POST)){
    unset($_SESSION['tit']);
    $user = null;
} elseif (array_key_exists('login', $_POST)){
    // print('post:<pre>'.print_r($_POST, true).'</pre>');
    $user = get_authenticated_user($db, $_POST['u'] ?? '', $_POST['p'] ?? '');
    if (isset($user)) {
        $_SESSION['tit'] = ['user' => $user['username'], 'password' => $user['password']];
    } else {
        $login_error = "Invalid username or password";
    }
} elseif (array_key_exists('tit', $_SESSION)) {
    $user = get_authenticated_user($db, $_SESSION['tit']['user'], $_SESSION['tit']['password']);
}

$login_html_template = <<<'EOD'
    <p>{{ message }}</p>
    <form method='POST' action='{{ action }}'>
      <label>Username</label><input type='text' name='u'>
      <label>Password</label><input type='password' name='p'>
      <label></label><input type='submit' name='login' value='Login'/>
    </form>
EOD;

// show login page on bad credential
if ($user === null) {
    die(TinyTemplate::factory()
        ->add('message', $login_error)
        ->add('action', $base_url)
        ->fetch($login_html_template, 'body')
        ->add('title', $config['title'])
        ->add('style', "body,input {font-family:sans-serif;font-size:11px;}\nlabel{display:block;}")
        ->add(['head'], '')
        ->fetch($base_html_template));
}

// create tables if not exist
$db->exec("CREATE TABLE IF NOT EXISTS issues (id INTEGER PRIMARY KEY, title TEXT, description TEXT, user TEXT, status INTEGER NOT NULL DEFAULT '0', priority INTEGER, notify_emails TEXT, entrytime DATETIME)");
@$db->exec("CREATE TABLE IF NOT EXISTS comments (id INTEGER PRIMARY KEY, issue_id INTEGER, user TEXT, description TEXT, entrytime DATETIME)");

$issue = [];
if (isset($_GET["id"])){
    // show issue #id
    $id=pdo_escape_string($_GET['id']);
    $issue = $db->query("SELECT id, title, description, user, status, priority, notify_emails, entrytime FROM issues WHERE id='$id'")->fetchAll();
    $comments = $db->query("SELECT id, user, description, entrytime FROM comments WHERE issue_id='$id' ORDER BY entrytime ASC")->fetchAll();
}

// if no issue found, go to list mode
if (count($issue)==0){

    unset($issue, $comments);
    // show all issues

    $status = 0;
    if (isset($_GET["status"]))
        $status = (int)$_GET["status"];

    $issues = $db->query(
        "SELECT id, title, description, user, status, priority, notify_emails, entrytime, comment_user, comment_time ".
        " FROM issues ".
        " LEFT JOIN (SELECT max(entrytime) as max_comment_time, issue_id FROM comments GROUP BY issue_id) AS cmax ON cmax.issue_id = issues.id".
        " LEFT JOIN (SELECT user AS comment_user, entrytime AS comment_time, issue_id FROM comments ORDER BY issue_id DESC, entrytime DESC) AS c ON c.issue_id = issues.id AND cmax.max_comment_time = c.comment_time".
        " WHERE status=".pdo_escape_string($status ? $status : "0 or status is null"). // <- this is for legacy purposes only
        " ORDER BY priority, entrytime DESC")->fetchAll();

    $mode="list";
}
else {
    $issue = $issue[0];
    $mode="issue";
}

//
// PROCESS ACTIONS
//

// Create / Edit issue

if (isset($_POST["createissue"])){
    $id=pdo_escape_string($_POST['id']);
    $title=pdo_escape_string($_POST['title']);
    $description=pdo_escape_string($_POST['description']);
    $priority=pdo_escape_string($_POST['priority']);
    $user=pdo_escape_string($user['username']);
    $now=date("Y-m-d H:i:s");

    // gather all emails
    $emails=array();
    for ($i=0;$i<count($users);$i++){
        if ($users[$i]["email"]!='') $emails[] = $users[$i]["email"];
    }
    $notify_emails = implode(",",$emails);

    if ($id=='')
        $query = "INSERT INTO issues (title, description, user, priority, notify_emails, entrytime) values('$title','$description','$user','$priority','$notify_emails','$now')"; // create
    else
        $query = "UPDATE issues SET title='$title', description='$description' WHERE id='$id'"; // edit

    if (trim($title)!='') {     // title cant be blank
        @$db->exec($query);
        if ($id==''){
            // created
            $id=$db->lastInsertId();
            if ($config['notify_issue_create'])
                notify( $id,
                                "[".$config['title']."] New Issue Created",
                                "New Issue Created by {$user}\r\nTitle: $title\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");
        }
        else{
            // edited
            if ($config['notify_issue_edit'])
                notify( $id,
                                "[".$config['title']."] Issue Edited",
                                "Issue edited by {$user}\r\nTitle: $title\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");
        }
    }

    header("Location: {$_SERVER['PHP_SELF']}");
}

// Delete issue
if (isset($_GET["deleteissue"])){
    $id=pdo_escape_string($_GET['id']);
    $title=get_col($id,"issues","title");

    // only the issue creator or admin can delete issue
    if ($user['admin'] || $user['username']==get_col($id,"issues","user")){
        @$db->exec("DELETE FROM issues WHERE id='$id'");
        @$db->exec("DELETE FROM comments WHERE issue_id='$id'");

        if ($config['notify_issue_delete'])
            notify( $id,
                            "[".$config['title']."] Issue Deleted",
                            "Issue deleted by {$user['username']}\r\nTitle: $title");
    }
    header("Location: {$_SERVER['PHP_SELF']}");

}

// Change Priority
if (isset($_GET["changepriority"])){
    $id=pdo_escape_string($_GET['id']);
    $priority=pdo_escape_string($_GET['priority']);
    if ($priority>=1 && $priority<=3) @$db->exec("UPDATE issues SET priority='$priority' WHERE id='$id'");

    if ($config['notify_issue_priority'])
        notify( $id,
                        "[".$config['title']."] Issue Priority Changed",
                        "Issue Priority changed by {$user['username']}\r\nTitle: ".get_col($id,"issues","title")."\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");

    header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

// change status
if (isset($_GET["changestatus"])){
    $id=pdo_escape_string($_GET['id']);
    $status=pdo_escape_string($_GET['status']);
    @$db->exec("UPDATE issues SET status='$status' WHERE id='$id'");

    if ($config['notify_issue_status'])
        notify( $id,
                        "[".$config['title']."] Issue Marked as ".$statuses[$status],
                        "Issue marked as {$statuses[$status]} by {$user['username']}\r\nTitle: ".get_col($id,"issues","title")."\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");

    header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

// Unwatch
if (isset($_POST["unwatch"])){
    $id=pdo_escape_string($_POST['id']);
    setWatch($id,false);       // remove from watch list
    header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

// Watch
if (isset($_POST["watch"])){
    $id=pdo_escape_string($_POST['id']);
    setWatch($id,true);         // add to watch list
    header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}


// Create Comment
if (isset($_POST["createcomment"])){

    $issue_id=pdo_escape_string($_POST['issue_id']);
    $description=pdo_escape_string($_POST['description']);
    $user=$user['username'];
    $now=date("Y-m-d H:i:s");

    if (trim($description)!=''){
        $query = "INSERT INTO comments (issue_id, description, user, entrytime) values('$issue_id','$description','$user','$now')"; // create
        $db->exec($query);
    }

    if ($config['notify_comment_create'])
        notify( $id,
                        "[".$config['title']."] New Comment Posted",
                        "New comment posted by {$user}\r\nTitle: ".get_col($id,"issues","title")."\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$issue_id");

    header("Location: {$_SERVER['PHP_SELF']}?id=$issue_id");

}

// Delete Comment
if (isset($_GET["deletecomment"])){
    $id=pdo_escape_string($_GET['id']);
    $cid=pdo_escape_string($_GET['cid']);

    // only comment poster or admin can delete comment
    if ($user['admin'] || $user['username']==get_col($cid,"comments","user"))
        $db->exec("DELETE FROM comments WHERE id='$cid'");

    header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

//
//      FUNCTIONS
//

// PDO quote, but without enclosing single-quote
function pdo_escape_string($str){
    global $db;
    $quoted = $db->quote($str);
    return ($db->quote("")=="''")?substr($quoted, 1, strlen($quoted)-2):$quoted;
}

function get_authenticated_user($db, $u, $p) {
    $u = mb_strtolower($u);
    $stmt = $db->prepare('SELECT username, password, email, admin FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $u]);
    if (($user = $stmt->fetch()) === false) {
        return null;
    }

    // TODO: a bit hacky, but is it really an issue?
    if (password_verify($p, $user['password']) || $p == $user['password']) {
        return $user;
    }
    return null;
}

// get column from some table with $id
function get_col($id, $table, $col){
    global $db;
    $result = $db->query("SELECT $col FROM $table WHERE id='$id'")->fetchAll();
    return $result[0][$col];
}

// notify via email
function notify($id, $subject, $body){
    global $db;
    $result = $db->query("SELECT notify_emails FROM issues WHERE id='$id'")->fetchAll();
    $to = $result[0]['notify_emails'];

    if ($to!=''){
        global $config;
        $headers = "From: ".$config['email']."" . "\r\n" . 'X-Mailer: PHP/' . phpversion();

        mail($to, $subject, $body, $headers);       // standard php mail, hope it passes spam filter :)
    }

}

// start/stop watching an issue
// TODO: use a lambda with use($user)
function watchFilterCallback($email) { global $user; return $email != $user['email']; }

function setWatch($id,$addToWatch){
    global $db;
    global $user;
    if ($user['email']=='') return;

    $result = $db->query("SELECT notify_emails FROM issues WHERE id='$id'")->fetchAll();
    $notify_emails = $result[0]['notify_emails'];

    $emails = $notify_emails ? explode(",",$notify_emails) : array();

    if ($addToWatch) $emails[] = $user['email'];
    else $emails = array_filter( $emails, "watchFilterCallback" );
    $emails = array_unique($emails);

    $notify_emails = implode(",",$emails);

    $db->exec("UPDATE issues SET notify_emails='$notify_emails' WHERE id='$id'");
}

$menu_html_template = <<<'EOD'
<form action="{{ base_url }}" method="POST" class="link"><input type="submit" name="logout" value="Logout"></form> {{ username }}
EOD;

$menu_html = TinyTemplate::factory()
    ->add('base_url', $base_url)
    ->add('username', $user['username'])
    ->fetch($menu_html_template);

$issue_row_html_template = <<<'EOD'
<tr class="p{{ priority }}">
    <td>#{{ id }}</td>
    <td><a href="{{ base_url }}?id={{ id }}">{{ title }}</a></td>
    <td>{{ user }}</td>
    <td>{{ entrytime }}</td>
    <td>{{ notify }}</td>
    <td>{{ activity }}</td>
</tr>
EOD;

$issues_row_html = [];
foreach ($issues as $issue) {
    $issue['notify'] = $user['email'] && strpos($issue['notify_emails'], $user['email']) !== FALSE ? '✓' : '';
    $issue['activity'] = $issue['comment_user'] ? date("M j",strtotime($issue['comment_time'])) . " (" . $issue['comment_user'] . ")" : "";
    $issues_row_html[] = TinyTemplate::factory()
        ->add('base_url', $base_url)
        ->add(['priority', 'id', 'title', 'user', 'entrytime', 'notify', 'activity'], $issue)
        ->fetch($issue_row_html_template);
}

$issues_html_template = <<<'EOD'
    <div id="list">
    <h2>{{ status }}Issues</h2>
        <table border=1 cellpadding=5 width="100%">
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Created by</th>
                <th>Date</th>
                <th><acronym title="Watching issue?">W</acronym></th>
                <th>Activity</th>
            </tr>
            {{ issues_rows|raw }}
        </table>
    </div>
EOD;

$issues_html = TinyTemplate::factory()
    ->add('title', array_key_exists('status', $_GET) && array_key_exists($_GET['status'], $statuses) ? $statuses[$_GET['status']]." " : '')
    ->add('issues_rows', implode("\n", $issues_row_html))
    ->fetch($issues_html_template);

ob_start();
?>

    <div id="menu">
        <?php
            foreach($statuses as $code=>$name) {
                $style=(isset($_GET['status']) && $_GET['status']==$code) || (isset($issue) && $issue['status']==$code)?"style='font-weight:bold;'":"";
                echo "<a href='{$_SERVER['PHP_SELF']}?status={$code}' alt='{$name} Issues' $style>{$name} Issues</a> | ";
            }
        ?>
        <?php if (isset($user)) : ?>
        <?= $menu_html ?>
        <?php endif; ?>
    </div>

    <h1><?= $config['title']; ?></h1>

    <h2><a href="#" onclick="document.getElementById('create').className='';document.getElementById('title').focus();"><?= isset($issue) ? "Edit" : "Create" ?> Issue <?= isset($issue) ? $issue['id'] : '' ?></a></h2>
    <div id="create" class='<?= isset($_GET['editissue'])?'':'hide'; ?>'>
        <a href="#" onclick="document.getElementById('create').className='hide';" style="float: right;">[Close]</a>
        <form method="POST">
            <input type="hidden" name="id" value="<?= $issue['id'] ?? '' ?>" />
            <label>Title</label><input type="text" size="50" name="title" id="title" value="<?= htmlentities($issue['title'] ?? '') ?>" />
            <label>Description</label><textarea name="description" rows="5" cols="50"><?= htmlentities($issue['description'] ?? '') ?></textarea>
            <label></label><input type="submit" name="createissue" value="<?= (isset($issue) ? "Edit" : "Create") ?>" />
<?php if (!isset($issue)) : ?>
            Priority
                <select name="priority">
                    <option value="1">High</option>
                    <option selected value="2">Medium</option>
                    <option value="3">Low</option>
                </select>
<?php endif ?>
        </form>
    </div>

    <?php if ($mode=="list"): ?>
    <?= $issues_html ?>
    <?php endif; ?>

    <?php if ($mode=="issue"): ?>
    <div id="show">
        <div class="issue">
            <h2><?php echo htmlentities($issue['title'],ENT_COMPAT,"UTF-8"); ?></h2>
            <p><?php echo nl2br( preg_replace("/([a-z]+:\/\/\S+)/","<a href='$1'>$1</a>", htmlentities($issue['description'],ENT_COMPAT,"UTF-8") ) ); ?></p>
        </div>
        <div class='left'>
            Priority <select name="priority" onchange="location='<?php echo $_SERVER['PHP_SELF']; ?>?changepriority&id=<?php echo $issue['id']; ?>&priority='+this.value">
                <option value="1"<?php echo ($issue['priority']==1?" selected":""); ?>>High</option>
                <option value="2"<?php echo ($issue['priority']==2?" selected":""); ?>>Medium</option>
                <option value="3"<?php echo ($issue['priority']==3?" selected":""); ?>>Low</option>
            </select>
            Status <select name="priority" onchange="location='<?php echo $_SERVER['PHP_SELF']; ?>?changestatus&id=<?php echo $issue['id']; ?>&status='+this.value">
            <?php foreach($statuses as $code=>$name): ?>
                <option value="<?php echo $code; ?>"<?php echo ($issue['status']==$code?" selected":""); ?>><?php echo $name; ?></option>
            <?php endforeach; ?>
            </select>
        </div>
        <div class='left'>
            <form method="POST">
                <input type="hidden" name="id" value="<?php echo $issue['id']; ?>" />
                <?php
                    if ($user['email']&&strpos($issue['notify_emails'],$user['email'])===FALSE)
                        echo "<input type='submit' name='watch' value='Watch' />\n";
                    else
                        echo "<input type='submit' name='unwatch' value='Unwatch' />\n";
                ?>
            </form>
        </div>
        <div class='clear'></div>
        <div id="comments">
            <?php
            if (count($comments)>0) echo "<h3>Comments</h3>\n";
            foreach ($comments as $comment){
                echo "<div class='comment' id='c".$comment['id']."'><p>".nl2br( preg_replace("/([a-z]+:\/\/\S+)/","<a href='$1'>$1</a>",htmlentities($comment['description'],ENT_COMPAT,"UTF-8") ) )."</p>";
                echo "<div class='comment-meta'><em>{$comment['user']}</em> on <em><a href='#c".$comment['id']."'>{$comment['entrytime']}</a></em> ";
                if ($user['admin'] || $user['username']==$comment['user']) echo "<span class='right'><a href='{$_SERVER['PHP_SELF']}?deletecomment&id={$issue['id']}&cid={$comment['id']}' onclick='return confirm(\"Are you sure?\");'>Delete</a></span>";
                echo "</div></div>\n";
            }
            ?>
            <div id="comment-create">
                <h4>Post a comment</h4>
                <form method="POST">
                    <input type="hidden" name="issue_id" value="<?php echo $issue['id']; ?>" />
                    <textarea name="description" rows="5" cols="50"></textarea>
                    <label></label>
                    <input type="submit" name="createcomment" value="Comment" />
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div id="footer">
        Powered by <a href="https://github.com/jwalanta/tit" alt="Tiny Issue Tracker" target="_blank">Tiny Issue Tracker</a>
    </div>
<?php
$body = ob_get_contents();
ob_end_clean();

$base_css_rules = <<<'EOD'
        html { overflow-y: scroll;}
        body { font-family: sans-serif; font-size: 11px; background-color: #aaa;}
        a, a:visited{color:#004989; text-decoration:none;}
        a:hover{color: #666; text-decoration: underline;}
        label{ display: block; font-weight: bold;}
        table{border-collapse: collapse;}
        th{text-align: left; background-color: #f2f2f2;}
        tr:hover{background-color: #f0f0f0;}
        #menu{float: right;}
        #container{width: 760px; margin: 0 auto; padding: 20px; background-color: #fff;}
        #footer{padding:10px 0 0 0; margin-top: 20px; text-align: center; border-top: 1px solid #ccc;}
        #create{padding: 15px; background-color: #f2f2f2;}
        .issue{padding:10px 20px; margin: 10px 0; background-color: #f2f2f2;}
        .comment{padding:5px 10px 10px 10px; margin: 10px 0; border: 1px solid #ccc;}
        .comment:target{outline: 2px solid #444;}
        .comment-meta{color: #666;}
        .p1, .p1 a{color: red;}
        .p3, .p3 a{color: #666;}
        .hide{display:none;}
        .left{float: left;}
        .right{float: right;}
        .clear{clear:both;}

        form.link {display:inline;}
        form.link input[type=submit] {background-color:transparent; border:none; cursor: pointer; color:#004989; font-size:11px; font-family:sans-serif; margin:0; padding:0;}
        form.link input[type=submit]:hover {color: #666; text-decoration:underline;}
EOD;

$title = $config['title'] . (isset($_GET["id"]) ? (" - #".$_GET["id"]) : "") . " - Issue Tracker";

print(TinyTemplate::factory()
    ->add('title', $title)
    ->add('body', $body)
    ->add('style', $base_css_rules)
    ->add(['head'], '')
    ->fetch($base_html_template));
