<?php

use \RedBeanPHP\R as R;
use Rudolf\OAuth2\Client\Provider\Reddit;

class Cronnit {
  private $config, $page, $reddit, $vars = [];

  public function __construct() {
    $this->config = (object)(include __DIR__."/config.php");

    foreach (['client_id', 'client_secret', 'dbdsn', 'dbuser', 'dbpass'] as $key) {
      if (!isset($this->config->$key)) {
        throw new \Exception("Missing configuration '$key'");
      }
    }
  }

  public function isLoggedIn() : bool {
    return isset($_SESSION['login']);
  }

  public function redirect($page) {
    $page = trim($page, '/');
    header("Location: /$page");
    exit(0);
    throw new \Exception("Execution should have ended");
  }

  public function getAccount() {
    $account = R::findOne('account', '`name`=?', [$_SESSION['login']]);

    if (empty($account)) {
      $_SESSION['error'] = "You must be logged in";
      $this->redirect("/");
    }

    return $account;
  }

  public function getPost(int $id) {
    return R::load('post', $id);
  }

  public function run() {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);

    $this->connect();
    $this->resolve();
    $this->render();
  }

  public function connect() {
    R::setup($this->config->dbdsn, $this->config->dbuser, $this->config->dbpass);
  }

  public function render() {
    try {
      session_start();
      ob_start();
      include __DIR__."/pages/{$this->page}.php";
      $this->vars['pageTemplate'] = "pages/{$this->page}.html";
      $this->vars['account'] = $this->isLoggedIn() ? $this->getAccount() : null;
      $this->renderTemplate();
      ob_end_flush();
    } catch (\Throwable $e) {
      ob_end_clean();
      trigger_error($e);
      $this->vars['pageTemplate'] = "error.html";
      $this->renderTemplate();
    }
  }

  protected function renderTemplate() {
    $loader = new Twig_Loader_Filesystem(__DIR__."/templates");
    $twig = new Twig_Environment($loader);
    $twig->addExtension(new Twig_Extensions_Extension_Date());
    echo $twig->render("site.html", $this->vars);
  }

  protected function resolve() {
    $page = trim($_SERVER['REQUEST_URI'], '/');
    $page = preg_replace('#\\?.*$#', '', $page);

    if (strpos($page, '.') !== false) {
      header("Location: /");
      exit(0);
    } else if ($page === "") {
      $page = "home";
    }

    if (!file_exists(__DIR__."/pages/$page.php")) {
      http_response_code(404);
      header("Location: /");
      exit(0);
    }

    $this->page = $page;
  }

  public static function serve() {
    $cronnit = new Cronnit();
    $cronnit->run();
  }

  public function getReddit() {
    return $this->reddit = $this->reddit ?: new Reddit([
      'clientId' => $this->config->client_id,
      'clientSecret' => $this->config->client_secret,
      'redirectUri' => "https://cronnit.us/authorize",
      'userAgent' => 'linux:cronnit:1.0, (by /u/KayRice)',
      'scopes' => ['identity', 'submit']
    ]);
  }

  public function api($accessToken, string $mode, $endpoint, array $data = []) {
    $request = $this->getReddit()->getAuthenticatedRequest(
      $mode,
      "https://oauth.reddit.com/$endpoint",
      $accessToken,
      $data
    );

    return json_decode((string)$this->getReddit()->getResponse($request)->getBody());
  }

  public function convertTime(string $date, string $time, string $zone) : int {
    $zone = new \DateTimeZone($zone);
    $date = \DateTime::createFromFormat('Y-m-d H:i', "$date $time", $zone);
    $date->setTimezone(new \DateTimeZone("UTC"));
    return $date->getTimestamp();
  }

  public function formatUserDate(int $timestamp, string $zone) {
    $date = new \DateTime("@$timestamp", new \DateTimeZone("UTC"));
    $date->setTimezone(new \DateTimeZone($zone));
    return $date->format("Y-m-d");
  }

  public function formatUserTime(int $timestamp, string $zone) {
    $date = new \DateTime("@$timestamp", new \DateTimeZone("UTC"));
    $date->setTimezone(new \DateTimeZone($zone));
    return $date->format("H:i");
  }

  public function checkPost(array $data) {
    foreach (['subreddit', 'title', 'body', 'whendate', 'whentime'] as $key) {
      if (!@is_string($data[$key]) || @strlen(trim($data[$key])) <= 0) {
        return "Missing $key";
      }
    }

    if (!preg_match('#^/r/[a-z_0-9]+$#', $data['subreddit'])) {
      return "Subreddit must be something like /r/name";
    }
  }
}
