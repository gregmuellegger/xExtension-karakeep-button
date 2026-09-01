<?php

class FreshExtension_karakeepButton_Controller extends Minz_ActionController
{
  /** Seconds to wait for the connection to the Karakeep instance to be established. */
  private const CONNECT_TIMEOUT = 5;

  /** Seconds to wait for the whole request to complete. */
  private const TIMEOUT = 15;

  /** @var KarakeepButton\View */
  protected $view;

  public function jsVarsAction(): void
  {
    $extension = Minz_ExtensionManager::findExtension('Karakeep Button');
    $added_to_karakeep_icon = $extension === null ? '' : $extension->getFileUrl('added_to_karakeep.svg');

    $this->view->karakeep_button_vars = array(
      'instance_url' => FreshRSS_Context::userConf()->attributeString('karakeep_instance_url'),
      'keyboard_shortcut' => FreshRSS_Context::userConf()->hasParam("karakeep_shortcut")
        ? FreshRSS_Context::userConf()->attributeString('karakeep_shortcut')
        : '',
      'icons' => array(
        'added_to_karakeep' => $added_to_karakeep_icon,
      ),
      'i18n' => array(
        'added_article_to_karakeep' => _t('ext.karakeepButton.notifications.added_article_to_karakeep', '%s'),
        'failed_to_add_article_to_karakeep' => _t('ext.karakeepButton.notifications.failed_to_add_article_to_karakeep', '%s'),
        'ajax_request_failed' => _t('ext.karakeepButton.notifications.ajax_request_failed'),
        'article_not_found' => _t('ext.karakeepButton.notifications.article_not_found'),
        'relog_required' => _t('ext.karakeepButton.notifications.relog_required'),
      )
    );

    $this->view->_layout(null);
    $this->view->_path('karakeepButton/vars.js');

    header('Content-Type: application/javascript; charset=utf-8');
  }

  /**
   * State changing actions must be POST requests carrying a valid CSRF token,
   * mirroring the protection FreshRSS core applies to its own POST actions.
   */
  private function isSafePostRequest(): bool
  {
    return Minz_Request::isPost() && FreshRSS_Auth::isCsrfOk();
  }

  /**
   * @return array{c:string,a:string,params:array<string,string>}
   */
  private function configureUrl(): array
  {
    return array('c' => 'extension', 'a' => 'configure', 'params' => array('e' => 'Karakeep Button'));
  }

  public function requestAccessAction(): void
  {
    if (!$this->isSafePostRequest()) {
      Minz_Request::bad(_t('feedback.access.denied'), $this->configureUrl());
      return;
    }

    $instance_url = rtrim(Minz_Request::paramString('instance_url'), '/');
    $api_token = Minz_Request::paramString('api_token');

    $url_redirect = $this->configureUrl();

    if (!$this->isValidInstanceUrl($instance_url)) {
      Minz_Request::bad(_t('ext.karakeepButton.notifications.invalid_instance_url'), $url_redirect);
      return;
    }

    // Validate the credentials before storing them, so that a failed attempt
    // leaves the existing configuration untouched.
    $result = $this->curlRequest('GET', '/users/me', null, $instance_url, $api_token);
    if ($result['status'] == 200) {
      FreshRSS_Context::userConf()->_attribute('karakeep_instance_url', $instance_url);
      FreshRSS_Context::userConf()->_attribute('karakeep_api_token', $api_token);
      FreshRSS_Context::userConf()->_attribute('karakeep_username', $this->extractUsername($result['response']));
      FreshRSS_Context::userConf()->save();

      Minz_Request::good(_t('ext.karakeepButton.notifications.authorized_success'), $url_redirect);
      return;
    }

    Minz_Request::bad(_t('ext.karakeepButton.notifications.request_access_failed', $result['status']), $url_redirect);
  }

  public function revokeAccessAction(): void
  {
    if (!$this->isSafePostRequest()) {
      Minz_Request::bad(_t('feedback.access.denied'), $this->configureUrl());
      return;
    }

    FreshRSS_Context::userConf()->_attribute('karakeep_instance_url');
    FreshRSS_Context::userConf()->_attribute('karakeep_api_token');
    FreshRSS_Context::userConf()->_attribute('karakeep_username');
    FreshRSS_Context::userConf()->save();

    $url_redirect = $this->configureUrl();
    Minz_Request::forward($url_redirect);
  }

  public function addAction(): void
  {
    $this->view->_layout(null);

    if (!$this->isSafePostRequest()) {
      header('HTTP/1.1 405 Method Not Allowed');
      echo json_encode(array('errorCode' => 405));
      return;
    }

    $entry_id = Minz_Request::paramString('id');
    $entry_dao = FreshRSS_Factory::createEntryDao();
    $entry = $entry_dao->searchById($entry_id);

    if ($entry === null) {
      echo json_encode(array('errorCode' => 404));
      return;
    }

    $post_data = array(
      'type' => 'link',
      'url' => $entry->link(),
      'source' => 'rss',
    );

    // Errors are handled in the JS
    $result = $this->curlRequest('POST', '/bookmarks', $post_data);
    $result['response'] = array('title' => $entry->title());
    echo json_encode($result);
  }

  /**
   * Only absolute http(s) URLs are usable as a Karakeep instance URL.
   */
  private function isValidInstanceUrl(string $instance_url): bool
  {
    $parts = parse_url($instance_url);
    if (!is_array($parts)) {
      return false;
    }

    $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
    $host = $parts['host'] ?? '';

    return in_array($scheme, array('http', 'https'), true) && $host !== '';
  }

  /**
   * Read the username out of a Karakeep /users/me response.
   */
  private function extractUsername(mixed $response): string
  {
    if (is_object($response) && isset($response->name) && is_string($response->name)) {
      return $response->name;
    }
    return '';
  }

  /**
   * @return array<string>
   */
  private function getRequestHeaders(string $api_token): array
  {
    return array(
      'Content-Type: application/json; charset=UTF-8',
      'Accept: application/json',
      "Authorization: Bearer " . $api_token,
    );
  }

  /**
   * @return \CurlHandle
   */
  private function getCurlBase(string $url, string $api_token): \CurlHandle
  {
    $headers = $this->getRequestHeaders($api_token);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
    curl_setopt($curl, CURLOPT_TIMEOUT, self::TIMEOUT);
    // The Karakeep API never redirects; following one would replay the API token
    // against whatever host the redirect points at.
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    return $curl;
  }

  /**
   * Perform a request against the Karakeep API.
   *
   * The instance URL and API token default to the values stored in the user
   * configuration, but can be overridden to test credentials that have not
   * been persisted (yet).
   *
   * @param array<string,mixed>|null $body
   * @return array<string,mixed>
   */
  private function curlRequest(string $method, string $endpoint, ?array $body = null, ?string $instance_url = null, ?string $api_token = null): array
  {
    $instance_url ??= FreshRSS_Context::userConf()->attributeString('karakeep_instance_url') ?? '';
    $api_token ??= FreshRSS_Context::userConf()->attributeString('karakeep_api_token') ?? '';
    $curl = $this->getCurlBase($instance_url . '/api/v1' . $endpoint, $api_token);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);

    if ($body !== null) {
      curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($curl);
    if (!is_string($response)) {
      $error = curl_error($curl);
      return array(
        'response' => null,
        'status' => 0,
        'errorCode' => 0,
        'error' => $error === '' ? 'cURL request failed' : $error,
      );
    }

    $status = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
    $header_size = intval(curl_getinfo($curl, CURLINFO_HEADER_SIZE));
    $response_headers = $this->httpHeaderToArray(substr($response, 0, $header_size));
    $response_body = substr($response, $header_size);

    return array(
      'response' => json_decode($response_body),
      'status' => $status,
      'errorCode' => isset($response_headers['x-error-code']) ? intval($response_headers['x-error-code']) : $status,
      'error' => null,
    );
  }

   /**
   * @return array<string,string>
   */
  private function httpHeaderToArray(string $header): array
  {
    $headers = array();
    $headers_parts = explode("\r\n", $header);

    foreach ($headers_parts as $header_part) {
      // skip empty header parts
      if (strlen($header_part) <= 0) {
        continue;
      }

      // Filter the beginning of the header which is the basic HTTP status code
      if (strpos($header_part, ':')) {
        $header_name = substr($header_part, 0, strpos($header_part, ':'));
        $header_value = substr($header_part, strpos($header_part, ':') + 1);
        $headers[$header_name] = trim($header_value);
      }
    }

    return $headers;
  }
}
