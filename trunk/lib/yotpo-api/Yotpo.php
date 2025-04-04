<?php

/**
 * Yotpo PHP inetrface for api.yotpo.com
 *
 * @author vlad
 */
class Yotpo {

    const VERSION = '0.0.5';
	const TIMEOUT = 5;
    protected static $app_key, $secret, $base_uri = 'https://api.yotpo.com';
    protected static $widgets_url = '/api/v2/widgets';
    protected $request;

    public function __construct($app_key = null, $secret = null, $base_uri = null) {
        $this->set_app_key($app_key);
       
        $this->set_secret($secret); 

        if ($base_uri != null) {
            self::$base_uri = $base_uri;
        }
    }

    protected function request($method, $url, $vars = array()) {
        if (!empty($vars)) {
            $vars = self::clean_array($vars);
        }

        $url = self::$base_uri . $url;
        $this->error = '';

        $args = [
          'method'    => strtoupper($method),
          'timeout'   => self::TIMEOUT,
          'headers'   => [
            'User-Agent' => 'Yotpo-Php',
          ],
          'sslverify' => false, // Equivalent to CURLOPT_SSL_VERIFYPEER & VERIFYHOST = false
        ];

        if (is_array($vars)) {
            if ($method === 'POST') {
                $args['body'] = wp_json_encode($vars);
                $args['headers']['Content-Type'] = 'application/json';
                $args['headers']['Content-Length'] = strlen($args['body']);
            } else {
                $url = add_query_arg($vars, $url);
            }
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->error = $response->get_error_message();
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);

        return self::process_response($response_body);
    }

    function get_widget_instances() {
        $params = $this->prepare_wigdets_request_params();
        return $this->request( 'GET', self::$widgets_url.'?'.$params );
    }

    protected function get($url, $vars = array()) {
        if (!empty($vars)) {
            $url .= (stripos($url, '?') !== false) ? '&' : '?';
            $url .= (is_string($vars)) ? $vars : http_build_query($vars, '', '&');
        }
        return $this->request('GET', $url);
    }
	
    protected function delete($url, $vars = array()) {
        return $this->request('DELETE', $url, $vars);
    }
	
    protected function post($url, $vars = array()) {
        return $this->request('POST', $url, $vars);
    }
	
    protected function put($url, $vars = array()) {
        return $this->request('PUT', $url, $vars);
    }
    
    protected static function process_response($response) {
		return json_decode($response, true);
    }

    public function check_if_b2c_user($email) {
        return $this->get('/users/find_by_type_and_email.json', array('type' => 'b2c', 'email' => $email));
    }

    public function create_user_migration($id, array $data) {
        return $this->post('/users/'.$id.'/migration', array('data' => $data));
    }

    public function notify_user_migration($id) {
        return $this->get('/users/'.$id.'/migration/notify');
    }

    public function create_user(array $user_hash, $install_step_done = false) {
        $response = $this->check_if_b2c_user($user_hash['email']);
        if (empty($response['response']['data'])){
            $user = array(
                'email' => $user_hash['email'],
                'display_name' => $user_hash['display_name'],
                'first_name' => $user_hash['first_name'],
                'last_name' => $user_hash['last_name'],
                'website_name' => $user_hash['website_name'],
                'password' => $user_hash['password'],
                'support_url' => $user_hash['support_url'],
                'callback_url' => $user_hash['callback_url'],
                'url' => $user_hash['url']
            );

            $data = $install_step_done ? array('install_step' => 'done', 'user' => $user) : array('user' => $user);
            return $this->post('/users', $data);

        } else {
            $id = $response['response']['data']['id'];
            $data = array(
                'password'=> $user_hash['password'],
                'display_name'=> $user_hash['display_name'],
                'account' => array(
                    'url' => $user_hash['url'],
                    'custom_platform_name'=>null,
                    'install_step'=>8,
                    'account_platform' => array(
                        'shop_domain'=> wc_yotpo_get_shop_domain(),
                        'platform_type_id'=>12,
                    )
                )
            );

            $this->create_user_migration($id,$data);
            $this->notify_user_migration($id);
            return 'b2c';
        }
    }

    public function get_oauth_token(array $credentials_hash = array()) {
        $request = array(
            'grant_type' => 'client_credentials'
        );

        $request['client_id'] = $this->get_app_key($credentials_hash);

        if (array_key_exists('secret', $credentials_hash)) {
            $request['client_secret'] = $credentials_hash['secret'];
        } else {
            $request['client_secret'] = self::$secret;
        }

        return $this->post('/oauth/token', $request);
    }

    public function create_account_platform(array $account_platform_hash) {
        $account_platform = self::build_request(array('shop_token' => 'shop_token', 'shop_domain' => 'shop_domain', 'plan_name' => 'plan_name', 'platform_type_id' => 'platform_type_id'), $account_platform_hash);
        $account_platform['deleted'] = false;
        $request = array('utoken' => $account_platform_hash['utoken'], 'account_platform' => $account_platform);
        $app_key = $this->get_app_key($account_platform_hash);
        return $this->post("/apps/$app_key/account_platform", $request);
    }

    public function get_login_url(array $credentials_hash = null) {
        $request = array();
        $request['app_key'] = $app_key = $this->get_app_key($credentials_hash);
        if (!is_null($credentials_hash) && array_key_exists('secret', $credentials_hash)) {
            $request['secret'] = $credentials_hash['secret'];
        } else {
            $request['secret'] = self::$secret;
        }

        return $this->get('/users/b2blogin.json', $request);
    }

    public function check_subdomain(array $subdomain_hash) {
        $app_key = $this->get_app_key($subdomain_hash);
        $subdomain = $subdomain_hash['subdomain'];
        if(is_null($subdomain)){
            throw 'subdomain Can not be blank';
        }
        $utoken = $subdomain_hash['utoken'];
        return $this->get("/apps/$app_key/subomain_check/$subdomain?utoken=$utoken");
    }

    public function update_account(array $account_hash) {
        $request = array(
            'account' => self::build_request(
                    array(
                'minisite_website_name' => 'minisite_website_name',
                'minisite_website' => 'minisite_website',
                'minisite_subdomain' => 'minisite_subdomain',
                'minisite_cname' => 'minisite_cname',
                'minisite_subdomain_active' => 'minisite_subdomain_active'
                    ), $account_hash),
            'utoken' => $account_hash['utoken']
        );

        $app_key = $this->get_app_key($account_hash);
        return $this->put("/apps/$app_key", $request);
    }

    public function create_purchase(array $purchase_hash) {
        $request = self::build_request(
                        array(
                    'utoken' => 'utoken',
                    'email' => 'email',
                    'customer_name' => 'customer_name',
                    'order_date' => 'order_date',
                    'currency_iso' => 'currency_iso',
                    'order_id' => 'order_id',
                    'platform' => 'platform',
                    'products' => 'products'
                        ), $purchase_hash);
        $app_key = $this->get_app_key($purchase_hash);
        return $this->post("/apps/$app_key/purchases", $request);
    }

    public function create_purchases(array $purchases_hash) {
        $request = self::build_request(array('utoken' => 'utoken', 'platform' => 'platform', 'orders' => 'orders'), $purchases_hash);
        $app_key = $this->get_app_key($purchases_hash);
        return $this->post("/apps/$app_key/purchases/mass_create", $request);
    }

    public function get_purchases(array $request_hash) {
        $request = self::build_request(array('utoken' => 'utoken', 'since_id' => 'since_id', 'since_date' => 'since_date', 'page' => 'page', 'count' => 'count'), $request_hash);
        if (!array_key_exists('page', $request)) {
            $request['page'] = 1;
        }
        if (!array_key_exists('count', $request)) {
            $request['count'] = 10;
        }
        $app_key = $this->get_app_key($request_hash);
        return $this->get("/apps/$app_key/purchases", $request);
    }

    public function send_test_reminder(array $reminder_hash) {
        $request = self::build_request(array('utoken' => 'utoken', 'email' => 'email'), $reminder_hash);
        $app_key = $this->get_app_key($reminder_hash);
        return $this->post("/apps/$app_key/reminders/send_test_email", $request);
    }

    public function get_all_bottom_lines(array $request_hash) {
        $request = self::build_request(array('utoken' => 'utoken', 'since_date' => 'since_date', 'since_id' => 'since_id'), $request_hash);
        $app_key = $this->get_app_key($request_hash);
        return $this->get("/apps/$app_key/bottom_lines", $request);
    }

    public function create_review(array $review_hash) {
        $params = array(
            'app_key' => 'appkey',
            'product_id' => 'sku',
            'domain' => 'shop_domain',
            'product_title' => 'product_title',
            'product_description' => 'product_description',
            'product_url' => 'product_url',
            'product_image_url' => 'product_image_url',
            'display_name' => 'user_display_name',
            'email' => 'user_email',
            'review_content' => 'review_body',
            'review_title' => 'review_title',
            'review_score' => 'review_score',
            'utoken' => 'utoken'
        );
        $request = self::build_request($params, $review_hash);

        return $this->get('/reviews/dynamic_create', $request);
    }

    public function get_product_reviews(array $request_hash) {
        $app_key = $this->get_app_key($request_hash);

        $product_id = $request_hash['product_id'];

        if (!$product_id) {
            throw 'product_id is mandatory for this request';
        }

        $request_params = array(
            'page' => $request_hash['page'],
            'count' => $request_hash['count'],
            'since_date' => $request_hash['since_date']
        );

        return $this->get("/products/$app_key/$product_id/reviews", $request_params);
    }

    public function get_product_bottom_line(array $request_hash) {
        $app_key = $this->get_app_key($request_hash);
        $product_id = $request_hash['product_id'];

        if (!$product_id) {
            throw 'product_id is mandatory for this request';
        }
        return $this->get("/products/$app_key/$product_id/bottomline");
    }
    
    public function set_app_key($app_key) {
        if ($app_key != null) {
            self::$app_key = $app_key;
        }
    }
     
    public function set_secret($secret) {
        if ($secret != null) {
            self::$secret = $secret;
        }
    }

    protected function get_app_key($hash){
        if(!is_null($hash) && !empty($hash) && array_key_exists('app_key', $hash)){
            return $hash['app_key'];
        } elseif (self::$app_key != null) {
            return self::$app_key; 
        } else {
            return null;
        }
    }

    protected static function build_request(array $params, array $request_params) {
        $request = array();
        foreach ($params as $key => $value) {
            if (array_key_exists($key, $request_params)) {
                $request[$value] = $request_params[$key];
            }
        }
        return $request;
    }
    
    protected static function clean_array(array $array){
        
        foreach( $array as $key => $value ) {
            if( is_array( $value ) ) {
                foreach( $value as $key2 => $value2 ) {
                    if( empty( $value2 ) ) 
                        unset( $array[ $key ][ $key2 ] );
                }
            }
            if( empty( $array[ $key ] ) )
                unset( $array[ $key ] );
        }
        return $array;
    }

    protected function prepare_wigdets_request_params() {
        return http_build_query(
            array(
                'app_key' => self::$app_key,
                'utoken' => $this->get_oauth_token()['access_token']
            )
        );
    }
}

?>
