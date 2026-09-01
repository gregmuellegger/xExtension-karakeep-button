<?php

class FreshExtension_karakeepButton_Controller extends Minz_ActionController
{
  /** @var KarakeepButton\View */
  protected $view;

  public function jsVarsAction(): void
  {
    $extension = Minz_ExtensionManager::findExtension('Karakeep Button');
    $this->view->karakeep_button_vars = json_encode(array(
      'instance_url' => FreshRSS_Context::userConf()->attributeString('karakeep_instance_url'),
      'keyboard_shortcut' => FreshRSS_Context::userConf()->hasParam("karakeep_shortcut")
        ? FreshRSS_Context::userConf()->attributeString('karakeep_shortcut')
        : '',
      'icons' => array(
        'added_to_karakeep' => $extension->getFileUrl('added_to_karakeep.svg', 'svg'),
      ),
      'i18n' => array(
        'added_article_to_karakeep' => _t('ext.karakeepButton.notifications.added_article_to_karakeep', '%s'),
        'failed_to_add_article_to_karakeep' => _t('ext.karakeepButton.notifications.failed_to_add_article_to_karakeep', '%s'),
        'ajax_request_failed' => _t('ext.karakeepButton.notifications.ajax_request_failed'),
        'article_not_found' => _t('ext.karakeepButton.notifications.article_not_found'),
        'relog_required' => _t('ext.karakeepButton.notifications.relog_required'),
      )
    ));

    $this->view->_layout(null);
    $this->view->_path('karakeepButton/vars.js');

    header('Content-Type: application/javascript; charset=utf-8');
  }

  public function requestAccessAction(): void
  {
    $instance_url = Minz_Request::paramString('instance_url');
    $api_token = Minz_Request::paramString('api_token');

    // Handle leading slash
    if (substr($instance_url, -1) == '/')
    {
      $instance_url = substr($instance_url, 0, -1);
    }

    $url_redirect = array('c' => 'extension', 'a' => 'configure', 'params' => array('e' => 'Karakeep Button'));

    // Validate the credentials before storing them, so that a failed attempt
    // leaves the existing configuration untouched.
    $result = $this->curlGetRequest('/users/me', $instance_url, $api_token);
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
    FreshRSS_Context::userConf()->_attribute('karakeep_instance_url');
    FreshRSS_Context::userConf()->_attribute('karakeep_api_token');
    FreshRSS_Context::userConf()->_attribute('karakeep_username');
    FreshRSS_Context::userConf()->save();

    $url_redirect = array('c' => 'extension', 'a' => 'configure', 'params' => array('e' => 'Karakeep Button'));
    Minz_Request::forward($url_redirect);
  }

  public function addAction(): void
  {
    $this->view->_layout(null);

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
    $result = $this->curlPostRequest('/bookmarks', $post_data);
    $result['response'] = array('title' => $entry->title());
    echo json_encode($result);
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
    return $curl;
  }

  /**
   * Perform a GET request against the Karakeep API.
   *
   * The instance URL and API token default to the values stored in the user
   * configuration, but can be overridden to test credentials that have not
   * been persisted (yet).
   *
   * @return array<string,mixed>
   */
  private function curlGetRequest(string $endpoint, ?string $instance_url = null, ?string $api_token = null): array
  {
    $instance_url ??= FreshRSS_Context::userConf()->attributeString('karakeep_instance_url');
    $api_token ??= FreshRSS_Context::userConf()->attributeString('karakeep_api_token');
    $curl = $this->getCurlBase($instance_url . "/api/v1" . $endpoint, $api_token);

    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');

    $response = curl_exec($curl);
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $response_header = substr($response, 0, $header_size);
    $response_body = substr($response, $header_size);
    $response_headers = $this->httpHeaderToArray($response_header);

    return array(
      'response' => json_decode($response_body),
      'status' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
      'errorCode' => isset($response_headers['x-error-code']) ? intval($response_headers['x-error-code']) : curl_getinfo($curl, CURLINFO_HTTP_CODE)
    );
  }

  /**
   * @param array<string,mixed> $post_data
   * @return array<string,mixed>
   */
  private function curlPostRequest(string $endpoint, array $post_data): array
  {
    $instance_url = FreshRSS_Context::userConf()->attributeString('karakeep_instance_url');
    $api_token = FreshRSS_Context::userConf()->attributeString('karakeep_api_token');
    $curl = $this->getCurlBase($instance_url . "/api/v1" . $endpoint, $api_token);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($post_data));

    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $response_header = substr($response, 0, $header_size);
    $response_body = substr($response, $header_size);
    $response_headers = $this->httpHeaderToArray($response_header);

    return array(
      'response' => json_decode($response_body),
      'status' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
      'errorCode' => isset($response_headers['x-error-code'])
        ? intval($response_headers['x-error-code'])
        : curl_getinfo($curl, CURLINFO_HTTP_CODE)
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
