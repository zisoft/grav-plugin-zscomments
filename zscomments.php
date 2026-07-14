<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Plugin;
use Grav\Common\Filesystem\RecursiveFolderFilterIterator;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Uri;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;

class ZscommentsPlugin extends Plugin
{
  protected $route = 'zscomments';
  protected $enable = false;
  protected $zscomments_cache_id;

  /**
   * @return array
   */
  public static function getSubscribedEvents()
  {
    return [
      'onPluginsInitialized' => ['onPluginsInitialized', 0],
      'onPagesInitialized' => ['onPagesInitialized', 0],
      'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
      'onApiSidebarItems' => ['onApiSidebarItems', 0],
      'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
    ];
  }

  public function onPagesInitialized()
  {
    $uri = $this->grav['uri'];
    $path = $uri->path();

    if ($path === '/zscomments-approve') {
      $this->handleCommentActionRoute(
        'approve',
        $uri->query('id'),
        $uri->query('url'),
        $this->grav['language']->getLanguage(),
        trim(strip_tags(urldecode((string)$uri->query('quickreply'))))
      );
      return;
    }

    if ($path === '/zscomments-delete') {
      $this->handleCommentActionRoute(
        'delete',
        $uri->query('id'),
        $uri->query('url'),
        $this->grav['language']->getLanguage(),
        ''
      );
      return;
    }
  }

  /**
   * Add the comment form information to the page header dynamically
   *
   * Used by Form plugin >= 2.0
   */
  public function onFormPageHeaderProcessed(Event $event)
  {
    $header = $event['header'];

    if ($this->enable) {
      if (!isset($header->form)) {
        $header->form = $this->getZscommentsFormConfig();
      }
    }

    $event->header = $header;
  }

  public function onTwigSiteVariables()
  {
    $enabled = $this->enable;
    $zscomments = $this->fetchZscomments();

    $this->grav['twig']->twig_vars['enable_zscomments_plugin'] = $enabled;
    $this->grav['twig']->twig_vars['zscomments'] = $zscomments;
    $this->grav['twig']->twig_vars['zscomments_form_config'] = $this->getZscommentsFormConfig();
  }

  private function getZscommentsFormConfig()
  {
    $defaults = [];
    $defaultConfigFile = __DIR__ . '/zscomments.yaml';

    if (is_file($defaultConfigFile)) {
      try {
        $defaultConfig = Yaml::parseFile($defaultConfigFile);
        $defaults = is_array($defaultConfig['form'] ?? null) ? $defaultConfig['form'] : [];
      } catch (\Throwable $e) {
        $defaults = [];
      }
    }

    $configured = $this->config->get('plugins.zscomments.form');
    $configured = is_array($configured) ? $configured : [];

    return array_replace($defaults, $configured);
  }

  /*
     * Frontend side initialization
     */
  public function initializeFrontend()
  {
    $this->calculateEnable();

    $this->enable([
      'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
    ]);

    if ($this->enable) {
      $this->enable([
        'onPageInitialized' => ['onPageInitialized', 100],
        'onFormProcessed' => ['onFormProcessed', 0],
        'onFormPageHeaderProcessed' => ['onFormPageHeaderProcessed', 0],
        'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
      ]);
    }

    //init cache id
    $this->zscomments_cache_id = $this->getZscommentsCacheId();
  }

  /**
   */
  public function onPluginsInitialized()
  {
    $this->grav['zscomments_plugin'] = $this;
    $this->initializeFrontend();
  }

  public function onPageInitialized(): void
  {
    if (!$this->enable) {
      return;
    }

    $page = $this->grav['page'] ?? null;

    if ($page instanceof PageInterface) {
      $this->registerZscommentsFormOnPage($page);
    }
  }

  public function autoload(): ClassLoader
  {
    $loader = new ClassLoader();
    $loader->addPsr4('Grav\\Plugin\\Zscomments\\', __DIR__ . '/classes');
    $loader->register();

    return $loader;
  }

  public function onApiRegisterRoutes(Event $event): void
  {
    $routes = $event['routes'];

    $routes->get('/zscomments-admin', [\Grav\Plugin\Zscomments\ZscommentsApiController::class, 'index']);
    $routes->post('/zscomments-admin/approve', [\Grav\Plugin\Zscomments\ZscommentsApiController::class, 'approve']);
    $routes->post('/zscomments-admin/delete', [\Grav\Plugin\Zscomments\ZscommentsApiController::class, 'delete']);
  }

  public function onApiSidebarItems(Event $event): void
  {
    $user = $event['user'] ?? null;

    if (!$user || !(bool)$user->get('access.api.super')) {
      return;
    }

    $items = $event['items'];
    $items[] = [
      'id' => $this->route,
      'plugin' => 'zscomments',
      'label' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.PLUGIN_TITLE', 'ZSComments'),
      'icon' => 'fa-comments',
      'route' => '/plugin/zscomments',
      'priority' => 80,
      'badge' => null,
    ];

    $event['items'] = $items;
  }

  public function onApiPluginPageInfo(Event $event): void
  {
    if (($event['plugin'] ?? null) !== 'zscomments') {
      return;
    }

    $user = $event['user'] ?? null;

    if (!$user || !(bool)$user->get('access.api.super')) {
      return;
    }

    $event['definition'] = [
      'id' => $this->route,
      'plugin' => 'zscomments',
      'title' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.PLUGIN_TITLE', 'ZSComments'),
      'icon' => 'fa-comments',
      'page_type' => 'component',
      'actions' => [
        [
          'id' => 'refresh',
          'label' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.REFRESH', 'Refresh'),
          'icon' => 'fa-rotate-right',
        ],
      ],
    ];
  }

  private function translateAdminLabel($key, $fallback)
  {
    $translated = $this->grav['language']->translate($key);

    return $translated !== $key ? $translated : $fallback;
  }

  private function normalizeCommentRoute($path)
  {
    $path = trim((string)$path);

    if ($path === '') {
      return '/';
    }

    $parsedPath = parse_url($path, PHP_URL_PATH);
    if (is_string($parsedPath) && $parsedPath !== '') {
      $path = $parsedPath;
    }

    $path = str_replace('\\', '/', $path);
    $path = '/' . ltrim($path, '/');
    $path = rtrim($path, '/');

    return $path !== '' ? $path : '/';
  }

  private function registerZscommentsFormOnPage(PageInterface $page): void
  {
    $form = $this->getZscommentsFormConfig();

    if (!is_array($form)) {
      return;
    }

    $formName = trim((string)($form['name'] ?? 'zscomments')) ?: 'zscomments';
    $form['name'] = $formName;

    $page->addForms([$formName => $form], true);
  }

  private function getZscommentsCacheId($path = null, $lang = null)
  {
    $cache = $this->grav['cache'];
    $path = $this->normalizeCommentRoute($path !== null ? $path : $this->grav['uri']->path());
    $lang = $lang !== null ? $lang : $this->grav['language']->getLanguage();

    return md5('zscomments-data' . $cache->getKey() . '-' . ($lang ?: '') . '-' . $path);
  }

  private function invalidateZscommentsCache($path = null, $lang = null)
  {
    $this->grav['cache']->delete($this->getZscommentsCacheId($path, $lang));
  }

  private function getZscommentsFilename($path, $lang = null)
  {
    $lang = $lang !== null ? $lang : $this->grav['language']->getLanguage();
    $path = $this->normalizeCommentRoute($path);

    $filename = DATA_DIR . 'zscomments';
    $filename .= ($lang ? '/' . $lang : '');
    $filename .= $path === '/' ? '/.yaml' : $path . '.yaml';

    return $filename;
  }

  private function getCommentTimezone()
  {
    $timezone = (string)$this->config->get('system.timezone');

    if ($timezone !== '') {
      try {
        return new \DateTimeZone($timezone);
      } catch (\Exception $e) {
      }
    }

    return new \DateTimeZone(date_default_timezone_get());
  }

  private function getCurrentCommentDate()
  {
    return (new \DateTime('now', $this->getCommentTimezone()))->format('Y-m-d H:i');
  }

  private function getCommentFileMetadata($filepath)
  {
    $basePath = rtrim(DATA_DIR . 'zscomments', '/');
    $relativePath = ltrim(substr($filepath, strlen($basePath)), '/');
    $relativePath = preg_replace('/\.yaml$/i', '', $relativePath);
    $lang = null;

    foreach ((array)$this->config->get('system.languages.supported', []) as $supportedLanguage) {
      $prefix = trim((string)$supportedLanguage, '/') . '/';

      if (Utils::startsWith($relativePath, $prefix)) {
        $lang = (string)$supportedLanguage;
        $relativePath = substr($relativePath, strlen($prefix));
        break;
      }
    }

    $route = '/' . ltrim((string)$relativePath, '/');

    return [
      'lang' => $lang,
      'route' => $route !== '//' ? $route : '/',
    ];
  }

  private function buildCommentData($text, $author, $email, $parentId = null, $isPending = 0)
  {
    return [
      'id' => Utils::uniqueId(),
      'parent_id' => $parentId,
      'is_pending' => $isPending,
      'text' => $this->sanitizeUtf8((string)$text),
      'date' => $this->getCurrentCommentDate(),
      'author' => $this->sanitizeUtf8((string)$author),
      'email' => $this->sanitizeUtf8((string)$email),
      'ip' => $this->config->get('plugins.zscomments.collect_ip', false) ? Uri::ip() : '-',
    ];
  }

  /**
   * Submitted comments are persisted to YAML as-is; a browser/bot sending
   * non-UTF-8 bytes (e.g. Latin-1) would otherwise sit in the file until an
   * admin listing tries to json_encode() it, silently failing the whole
   * response instead of just that one comment (see API unhandled exception,
   * Stream::create() TypeError). mb_convert_encoding(UTF-8, UTF-8) replaces
   * ill-formed byte sequences with the substitute character, same effect as
   * mb_scrub() which requires PHP 8.1+.
   */
  private function sanitizeUtf8(string $value): string
  {
    if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
      return $value;
    }

    return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
  }

  private function updateCommentInFile($filename, $id, callable $updater)
  {
    $updated = false;

    $this->updateYamlFileWithLock($filename, function ($data) use ($id, $updater, &$updated) {
      if (!is_array($data) || !isset($data['comments']) || !is_array($data['comments'])) {
        return $data;
      }

      foreach ($data['comments'] as $index => $comment) {
        if (isset($comment['id']) && $comment['id'] === $id) {
          $updated = true;
          $data = $updater($data, $index);
          break;
        }
      }

      return $data;
    });

    return $updated;
  }

  public function performCommentAction($action, $id, $url, $lang = null, $quickreply = '')
  {
    if (!$id || !$url) {
      return false;
    }

    $filename = $this->getZscommentsFilename($url, $lang);
    $updated = false;

    switch ($action) {
      case 'approve':
        $updated = $this->updateCommentInFile($filename, $id, function ($data, $index) use ($id, $quickreply) {
          $data['comments'][$index]['is_pending'] = 0;

          if ($quickreply !== '') {
            $data['comments'][] = $this->buildCommentData(
              $quickreply,
              trim((string)$this->config->get('plugins.zscomments.quickreply_name', '')),
              trim((string)$this->config->get('plugins.zscomments.quickreply_email', '')),
              $id,
              0
            );
          }

          return $data;
        });
        break;

      case 'delete':
        $updated = $this->updateCommentInFile($filename, $id, function ($data, $index) {
          unset($data['comments'][$index]);
          $data['comments'] = array_values($data['comments']);

          return $data;
        });
        break;
    }

    if ($updated) {
      $this->invalidateZscommentsCache($url, $lang);
    }

    return $updated;
  }

  public function getAdminPageData($page = 0, array $filters = [])
  {
    $normalizedFilters = $this->normalizeAdminCommentFilters($filters);
    $comments = $this->getLastComments((int)$page, $normalizedFilters);

    return [
      'comments' => $comments->comments,
      'page' => $comments->page,
      'totalAvailable' => $comments->totalAvailable,
      'totalRetrieved' => $comments->totalRetrieved,
      'pages' => $this->fetchPages($normalizedFilters),
      'filters' => [
        'range' => $normalizedFilters['range'],
        'pending_only' => $normalizedFilters['pending_only'],
        'route' => $normalizedFilters['route'],
        'search' => $normalizedFilters['search'],
      ],
      'labels' => [
        'plugin_title' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.PLUGIN_TITLE', 'ZSComments'),
        'summary_loaded' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.SUMMARY_LOADED', '%retrieved% von %total% Kommentaren geladen'),
        'refresh' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.REFRESH', 'Refresh'),
        'comments_title' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.COMMENTS_TITLE', 'Recent comments'),
        'loading_comments' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.LOADING_COMMENTS', 'Loading comments …'),
        'no_comments' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.NO_COMMENTS', 'No comments found.'),
        'author_unknown' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.AUTHOR_UNKNOWN', 'Unknown'),
        'status_pending' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.STATUS_PENDING', 'Pending'),
        'status_approved' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.STATUS_APPROVED', 'Approved'),
        'approve' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.APPROVE', 'Approve'),
        'approve_running' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.APPROVE_RUNNING', 'Approving …'),
        'delete' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.DELETE', 'Delete'),
        'delete_running' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.DELETE_RUNNING', 'Deleting …'),
        'quickreply_placeholder' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.QUICKREPLY_PLACEHOLDER', 'Optional quick reply for approval'),
        'load_more' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.LOAD_MORE', 'Load more'),
        'pages_title' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.PAGES_TITLE', 'Recently commented pages'),
        'no_pages' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.NO_PAGES', 'No commented pages yet.'),
        'column_author' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.COLUMN_AUTHOR', 'Author'),
        'column_page' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.COLUMN_PAGE', 'Page'),
        'column_date' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.COLUMN_DATE', 'Date'),
        'column_status' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.COLUMN_STATUS', 'Status'),
        'column_actions' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.COLUMN_ACTIONS', 'Actions'),
        'column_route' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.COLUMN_ROUTE', 'Route'),
        'column_comments' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.COLUMN_COMMENTS', 'Comments'),
        'column_last_comment' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.COLUMN_LAST_COMMENT', 'Last comment'),
        'filter_range' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.FILTER_RANGE', 'Time range'),
        'filter_range_7d' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.FILTER_RANGE_7D', 'last 7 days'),
        'filter_range_30d' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.FILTER_RANGE_30D', 'last 30 days'),
        'filter_range_all' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.FILTER_RANGE_ALL', 'all'),
        'filter_pending_only' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.FILTER_PENDING_ONLY', 'only pending'),
        'filter_route' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.FILTER_ROUTE', 'Route'),
        'filter_route_placeholder' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.FILTER_ROUTE_PLACEHOLDER', 'e.g. /site/about'),
        'filter_search' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.FILTER_SEARCH', 'Text search'),
        'filter_search_placeholder' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.FILTER_SEARCH_PLACEHOLDER', 'Search comment text'),
        'confirm_delete' => $this->translateAdminLabel('ICU.PLUGIN_ZSCOMMENTS.ADMIN.CONFIRM_DELETE', 'Do you really want to delete this comment?'),
      ],
    ];
  }

  private function handleCommentActionRoute($action, $id, $url, $lang, $quickreply = '')
  {
    $this->performCommentAction($action, $id, $url, $lang, $quickreply);
    $this->grav->redirect($url ?: '/', 303);
  }

  /**
   * Determine if the plugin should be enabled based on the enable_on_routes and disable_on_routes config options
   */
  private function calculateEnable()
  {
    $uri = $this->grav['uri'];

    $disable_on_routes = (array)$this->config->get('plugins.zscomments.disable_on_routes');
    $enable_on_routes = (array)$this->config->get('plugins.zscomments.enable_on_routes');

    $path = $uri->path();

    if (!in_array($path, $disable_on_routes)) {
      if (in_array($path, $enable_on_routes)) {
        $this->enable = true;
      } else {
        foreach ($enable_on_routes as $route) {
          if (Utils::startsWith($path, $route)) {
            $this->enable = true;
            break;
          }
        }
      }
    }
  }

  /**
   * Handle form processing instructions.
   *
   * @param Event $event
   */
  public function onFormProcessed(Event $event)
  {
    $form = $event['form'];
    $action = $event['action'];
    $params = $event['params'];

    if (!$this->active) {
      return;
    }

    // Honeypot protection - cancel the entire form process (email, addComment, etc.)
    // as soon as the honeypot field is filled in, whatever process step is currently firing.
    $honeypotFieldName = $this->config->get('plugins.zscomments.honeypot_field_name');
    if ($honeypotFieldName) {
      $post = isset($_POST['data']) ? $_POST['data'] : [];
      $honeypot = $post[$honeypotFieldName] ?? '';

      if ($honeypot !== '') {
        $event->stopPropagation();
        return;
      }
    }

    // Blocked scripts protection - cancel the entire form process if the comment text or
    // name matches the admin-configured regular expression.
    $blockedScripts = trim((string)$this->config->get('plugins.zscomments.blocked_scripts', ''));
    if ($blockedScripts !== '') {
      $post = isset($_POST['data']) ? $_POST['data'] : [];
      $content = ((string)($post['text'] ?? '')) . ' ' . ((string)($post['name'] ?? ''));

      // Use a control character as delimiter so the admin-supplied pattern can't break out
      // of it by containing '/' or ']', and validate it before trusting the match result -
      // an invalid pattern must fail open (skip the filter), not silently match everything.
      $pattern = "\x01" . $blockedScripts . "\x01u";
      $matched = @preg_match($pattern, $content);

      var_dump($matched);

      if ($matched === false) {
        $this->grav['log']->warning('zscomments: invalid "blocked_scripts" regex, comment filter skipped: ' . $blockedScripts);
      } elseif ($matched === 1) {
        $event->stopPropagation();
        return;
      }
    }

    switch ($action) {
      case 'addComment':
        $post = isset($_POST['data']) ? $_POST['data'] : [];

        // Get path - fallback to current URI if not provided or contains Twig syntax
        $pathRaw = trim((string)($post['path'] ?? ''));
        if (empty($pathRaw) || str_contains($pathRaw, '{{') || str_contains($pathRaw, '{%')) {
          $pathRaw = $this->grav['uri']->path();
        }
        $path = $this->normalizeCommentRoute($pathRaw);

        $lang = trim(strip_tags(urldecode((string)($post['lang'] ?? ''))));
        $text = trim(strip_tags(urldecode((string)($post['text'] ?? ''))));
        $parent_id = trim(strip_tags(urldecode((string)($post['parent_id'] ?? ''))));
        $name = trim(strip_tags(urldecode((string)($post['name'] ?? ''))));
        $email = trim(urldecode((string)($post['email'] ?? '')));
        $title = trim(strip_tags(urldecode((string)($post['title'] ?? ''))));

        if (isset($this->grav['user'])) {
          $user = $this->grav['user'];
          if ($user->authenticated) {
            $name = $user->fullname;
            $email = trim($user->email);
          }
        }

        /** @var Language $language */
        $language = $this->grav['language'];

        // If lang is empty or contains Twig syntax, get it from language service
        if (empty($lang) || str_contains($lang, '{{') || str_contains($lang, '{%')) {
          $lang = $language->getLanguage();
        }

        // If title is empty or contains Twig syntax, get it from the current page
        if (empty($title) || str_contains($title, '{{') || str_contains($title, '{%')) {
          $currentPage = $this->grav['page'];
          if ($currentPage instanceof PageInterface) {
            $title = $currentPage->title();
          }
        }

        $filename = $this->getZscommentsFilename($path, $lang);

        $require_approval = (bool)$this->config->get('plugins.zscomments.require_approval', true);

        $comment = $this->buildCommentData(
          $text,
          $name,
          $email,
          $parent_id,
          $require_approval ? 1 : 0
        );

        $this->updateYamlFileWithLock($filename, function ($data) use ($title, $lang, $comment) {
          if (!is_array($data)) {
            $data = [
              'title' => $title,
              'lang' => $lang,
              'comments' => []
            ];
          }

          if (!isset($data['title']) || str_contains((string)$data['title'], '{{') || str_contains((string)$data['title'], '{%')) {
            $data['title'] = $title;
          }

          if (!isset($data['lang']) || str_contains((string)$data['lang'], '{{') || str_contains((string)$data['lang'], '{%')) {
            $data['lang'] = $lang;
          }

          if (!isset($data['comments']) || !is_array($data['comments'])) {
            $data['comments'] = [];
          }

          $data['comments'][] = $comment;

          return $data;
        });

        //clear cache
        $this->invalidateZscommentsCache($path, $lang);

        // send email
        $uri = $this->grav['uri'];
        $vars = [
          'base_uri' => $uri->rootUrl(true),
          'page' => $this->grav['page'],
          'comment' => $comment
        ];

        $to = trim((string)$this->config->get('plugins.zscomments.approval_email', 'mail@zisoft.de'));
        $from = trim((string)$this->config->get('plugins.zscomments.approval_from', 'zisoft Grav CMS <norply@zisoft.de>'));
        $subject = trim((string)$this->config->get('plugins.zscomments.approval_subject', 'Neuer Kommentar auf zisoft.de'));
        $content = $this->grav['twig']->processTemplate('/partials/zscomments_email.html.twig', $vars);

        $message = $this->grav['Email']->message($subject, $content, 'text/html')
          ->setFrom($from)
          ->setTo($to);

        $sent = $this->grav['Email']->send($message);

        break;
    }
  }

  private function normalizeAdminCommentFilters(array $filters = [])
  {
    $range = isset($filters['range']) ? (string)$filters['range'] : '7d';
    $pendingOnly = !empty($filters['pending_only']);
    $route = trim((string)($filters['route'] ?? ''));
    $search = trim((string)($filters['search'] ?? ''));

    if (!in_array($range, ['7d', '30d', 'all'], true)) {
      $range = '7d';
    }

    return [
      'range' => $range,
      'pending_only' => $pendingOnly,
      'route' => $route,
      'search' => $search,
      'min_timestamp' => match ($range) {
        '30d' => time() - (30 * 24 * 60 * 60),
        'all' => null,
        default => time() - (7 * 24 * 60 * 60),
      },
    ];
  }

  private function commentMatchesAdminFilters(array $comment, array $filters)
  {
    if (!empty($filters['pending_only']) && (int)($comment['is_pending'] ?? 0) !== 1) {
      return false;
    }

    if ($filters['min_timestamp'] !== null && ($comment['timestamp'] ?? 0) < $filters['min_timestamp']) {
      return false;
    }

    if ($filters['route'] !== '') {
      $routeHaystack = trim(((string)($comment['url'] ?? '')) . ' ' . ((string)($comment['pageTitle'] ?? '')) . ' ' . ((string)($comment['lang'] ?? '')));

      if (stripos($routeHaystack, $filters['route']) === false) {
        return false;
      }
    }

    if ($filters['search'] !== '') {
      $searchHaystack = trim(((string)($comment['text'] ?? '')) . ' ' . ((string)($comment['author'] ?? '')));

      if (stripos($searchHaystack, $filters['search']) === false) {
        return false;
      }
    }

    return true;
  }

  private function getCommentFileLockTimeout()
  {
    $timeout = (float)$this->config->get('plugins.zscomments.lock_timeout', 5.0);

    return $timeout > 0 ? $timeout : 5.0;
  }

  private function getCommentFileLockRetryDelay()
  {
    $retryDelay = (int)$this->config->get('plugins.zscomments.lock_retry_delay', 100000);

    return $retryDelay > 0 ? $retryDelay : 100000;
  }

  private function getCommentFileLockStaleTimeout()
  {
    $default = max($this->getCommentFileLockTimeout() * 4, 30.0);
    $staleTimeout = (float)$this->config->get('plugins.zscomments.lock_stale_timeout', $default);

    return $staleTimeout > 0 ? $staleTimeout : $default;
  }

  private function clearStaleCommentLockDirectory($lockPath, $staleTimeout)
  {
    clearstatcache(true, $lockPath);

    if (!is_dir($lockPath)) {
      return;
    }

    $modifiedTime = @filemtime($lockPath);

    if ($modifiedTime !== false && $modifiedTime >= (time() - $staleTimeout)) {
      return;
    }

    @rmdir($lockPath);
    clearstatcache(true, $lockPath);
  }

  private function withCommentFileLock($filename, $lockType, callable $callback)
  {
    $lockPath = $filename . '.lock';
    $lockDirectory = dirname($lockPath);
    $timeout = $this->getCommentFileLockTimeout();
    $retryDelay = $this->getCommentFileLockRetryDelay();
    $staleTimeout = $this->getCommentFileLockStaleTimeout();
    $lockAcquired = false;

    if (!file_exists($lockDirectory)) {
      Folder::mkdir($lockDirectory);
    }

    $deadline = microtime(true) + $timeout;

    try {
      do {
        clearstatcache(true, $lockPath);

        // $lockType bleibt Teil der Signatur, auch wenn das Verzeichnis-Locking
        // Leser und Schreiber gleichermaßen exklusiv serialisiert.
        if (@mkdir($lockPath, 0775)) {
          $lockAcquired = true;
          @touch($lockPath);

          return $callback();
        }

        $this->clearStaleCommentLockDirectory($lockPath, $staleTimeout);

        if (microtime(true) >= $deadline) {
          throw new \RuntimeException(sprintf('Timeout while waiting for lock "%s".', $lockPath));
        }

        usleep($retryDelay);
      } while (true);
    } finally {
      if ($lockAcquired) {
        @rmdir($lockPath);
        clearstatcache(true, $lockPath);
      }
    }
  }

  private function readYamlFileWithLock($filename)
  {
    return $this->withCommentFileLock($filename, LOCK_SH, function () use ($filename) {
      clearstatcache(true, $filename);

      if (!is_file($filename) || filesize($filename) === 0) {
        return null;
      }

      $content = file_get_contents($filename);

      if ($content === false || $content === '') {
        return null;
      }

      return Yaml::parse($content);
    });
  }

  private function updateYamlFileWithLock($filename, callable $callback)
  {
    return $this->withCommentFileLock($filename, LOCK_EX, function () use ($filename, $callback) {
      $path = dirname($filename);

      if (!file_exists($path)) {
        Folder::mkdir($path);
      }

      clearstatcache(true, $filename);

      $data = null;

      if (is_file($filename) && filesize($filename) > 0) {
        $content = file_get_contents($filename);

        if ($content === false) {
          throw new \RuntimeException(sprintf('Unable to read YAML file "%s".', $filename));
        }

        $data = Yaml::parse($content);
      }

      $updatedData = $callback($data);
      $tempFilename = tempnam($path, 'zscomments_');

      if ($tempFilename === false) {
        throw new \RuntimeException(sprintf('Unable to create temp file for "%s".', $filename));
      }

      $yaml = Yaml::dump($updatedData);

      if (file_put_contents($tempFilename, $yaml) === false) {
        @unlink($tempFilename);
        throw new \RuntimeException(sprintf('Unable to write temp YAML file for "%s".', $filename));
      }

      if (!rename($tempFilename, $filename)) {
        @unlink($tempFilename);
        throw new \RuntimeException(sprintf('Unable to replace YAML file "%s".', $filename));
      }

      return $updatedData;
    });
  }

  private function getFilesOrderedByModifiedDate($path = '', $minModifiedTimestamp = null)
  {
    $files = [];

    if (!$path) {
      $path = DATA_DIR . 'zscomments';
    }

    if (!file_exists($path)) {
      Folder::mkdir($path);
    }

    $dirItr = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
    $filterItr = new RecursiveFolderFilterIterator($dirItr);
    $itr = new \RecursiveIteratorIterator($filterItr, \RecursiveIteratorIterator::SELF_FIRST);

    $itrItr = new \RecursiveIteratorIterator($dirItr, \RecursiveIteratorIterator::SELF_FIRST);
    $filesItr = new \RegexIterator($itrItr, '/^.+\.yaml$/i');

    foreach ($filesItr as $filepath => $file) {
      $modifiedDate = $file->getMTime();

      if ($minModifiedTimestamp !== null && $modifiedDate < $minModifiedTimestamp) {
        continue;
      }

      $data = $this->readYamlFileWithLock($filepath);

      if (!is_array($data)) {
        continue;
      }

      $fileMetadata = $this->getCommentFileMetadata($filepath);

      $files[] = (object)array(
        "modifiedDate" => $modifiedDate,
        "fileName" => $file->getFilename(),
        "filePath" => $filepath,
        "route" => $fileMetadata['route'],
        "lang" => $fileMetadata['lang'],
        "data" => $data
      );
    }

    // Traverse folders and recurse
    foreach ($itr as $file) {
      if ($file->isDir()) {
        $this->getFilesOrderedByModifiedDate($file->getPath() . '/' . $file->getFilename(), $minModifiedTimestamp);
      }
    }

    // Order files by last modified date
    usort($files, function ($a, $b) {
      return $b->modifiedDate <=> $a->modifiedDate;
    });

    return $files;
  }

  private function getLastComments($page = 0, array $filters = [])
  {
    $number = 30;
    $filters = $this->normalizeAdminCommentFilters($filters);

    $files = [];
    $files = $this->getFilesOrderedByModifiedDate('', $filters['min_timestamp']);
    $comments = [];

    foreach ($files as $file) {
      $data = $file->data;

      if (!is_array($data) || !isset($data['comments']) || !is_array($data['comments'])) {
        continue;
      }

      for ($i = 0; $i < count($data['comments']); $i++) {
        $comment = $data['comments'][$i];
        $dateTime = \DateTime::createFromFormat('Y-m-d H:i', (string)($comment['date'] ?? ''), $this->getCommentTimezone());

        $comment['pageTitle'] = $data['title'] ?? $file->route;
        $comment['filePath'] = $file->filePath;
        $comment['timestamp'] = $dateTime instanceof \DateTimeInterface ? $dateTime->getTimestamp() : 0;
        $comment['url'] = $file->route;
        $comment['lang'] = $file->lang;

        if ($this->commentMatchesAdminFilters($comment, $filters)) {
          $comments[] = $comment;
        }
      }
    }

    // Order comments by date
    usort($comments, function ($a, $b) {
      return $b['timestamp'] <=> $a['timestamp'];
    });

    $totalAvailable = count($comments);
    $comments = array_slice($comments, $page * $number, $number);
    $totalRetrieved = count($comments);

    return (object)array(
      "comments" => $comments,
      "page" => $page,
      "totalAvailable" => $totalAvailable,
      "totalRetrieved" => $totalRetrieved
    );
  }

  /**
   * Return the comments associated to the current route
   */
  private function fetchZscomments()
  {
    $cache = $this->grav['cache'];
    //search in cache
    if ($zscomments = $cache->fetch($this->zscomments_cache_id)) {
      return $zscomments;
    }

    $lang = $this->grav['language']->getLanguage();
    $filename = $this->getZscommentsFilename($this->grav['uri']->path(), $lang);

    $data = $this->readYamlFileWithLock($filename);
    $zscomments = isset($data['comments']) ? $data['comments'] : null;
    //save to cache if enabled
    $cache->save($this->zscomments_cache_id, $zscomments);
    return $zscomments;
  }

  /**
   * Return the latest commented pages
   */
  private function fetchPages(array $filters = [])
  {
    $filters = $this->normalizeAdminCommentFilters($filters);
    $files = [];
    $files = $this->getFilesOrderedByModifiedDate('', $filters['min_timestamp']);

    $pages = [];

    foreach ($files as $file) {
      $matchingComments = [];

      foreach ((array)($file->data['comments'] ?? []) as $comment) {
        $dateTime = \DateTime::createFromFormat('Y-m-d H:i', (string)($comment['date'] ?? ''), $this->getCommentTimezone());

        $comment['timestamp'] = $dateTime instanceof \DateTimeInterface ? $dateTime->getTimestamp() : 0;
        $comment['url'] = $file->route;
        $comment['pageTitle'] = $file->data['title'] ?? $file->route;
        $comment['lang'] = $file->lang;

        if ($this->commentMatchesAdminFilters($comment, $filters)) {
          $matchingComments[] = $comment;
        }
      }

      if (!count($matchingComments)) {
        continue;
      }

      usort($matchingComments, function ($a, $b) {
        return $b['timestamp'] <=> $a['timestamp'];
      });

      $pages[] = [
        'title' => $file->data['title'] ?? $file->route,
        'route' => $file->route,
        'lang' => $file->lang,
        'commentsCount' => count($matchingComments),
        'lastCommentDate' => date('D, d M Y H:i:s', $matchingComments[0]['timestamp'])
      ];
    }

    return $pages;
  }


  /**
   * Given a data file route, return the YAML content already parsed
   */
  private function getDataFromFilename($fileRoute)
  {

    //Single item details
    return $this->readYamlFileWithLock(DATA_DIR . 'zscomments/' . $fileRoute);
  }

  /**
   * Add templates directory to twig lookup paths.
   */
  public function onTwigTemplatePaths()
  {
    $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
  }
}
