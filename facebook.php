<?php

class FacebookComponent extends Object {
    
    public $components = array('Session');
    
    /**
     * Session component object
     *
     * @var SessionComponent
     * @access public
     */
    public $Session;
 
    /**
     * Component default configuration
     *
     * @var array
     * @access private
     */
    private $_defaults = array(
        'appUrl' => 'YOUR_APP_URL', //i. e. http://apps.facebook.com/my_genius_app
        'appId' => 'YOUR_APP_ID', //App ID/API Key
        'secret' => 'YOUR_APP_SECRET', //App Secret
        'permissions' => 'APP_PERMS_LIST', //Comma separated permissions list
        'pageId' => 'PAGE_ID', //Facebook fanpage id
    );
    
    /**
     * Facebook user id for tests
     *
     * @var string
     * @access private
     */
    private $_testAccountId = '100002193156452';
    
    /**
     * Current component configuration
     *
     * @var array
     */
    public $settings = null;

    /**
     *
     * @var Facebook
     */
    public $facebook = null;
    public $session = null;
    
    /**
     * Facebook uid of currently logged in user
     *
     * @var int
     */
    public $uid = null;
    
    /**
     * Call SDK methods directly from component
     */
    public function __call($method, $arguments) {
        if (method_exists($this->facebook, $method)) {
            try {
                return call_user_func_array(
                    array($this->facebook, $method),
                    $arguments
                );
            } catch (FacebookApiException $e) {
                $this->_exception($e);
                return false;
            }
        }
        return false;
    }
    
    /**
     * Error handling
     *
     * @param FacebookApiException $e Exception object
     */
    private function _exception(FacebookApiException $e) {
        $error_msg = $e->getType() . ': ' . $e->getMessage();
        $this->log($error_msg, LOG_DEBUG);
        $this->Session->setFlash($error_msg);
        switch ($e->getType()) {
            case 'OAuthException': 
//                $this->_authRedirect();
                break;
            default:
                break;
        }
    }

    public function initialize(&$controller, $settings = array()) {
        $this->controller = & $controller;
        $this->settings = array_merge($this->_defaults, $settings);
    }
    
    /**
     * Configure component
     *
     * @param array $settings 
     */
    public function settings($settings = array()) {
        $this->settings = array_merge($this->settings, $settings);
    }
    
    /**
     * Init SDK (and connect to facebook?)
     *
     * @param boolean $start If true, connect to facebook api and get current user data. Defaults to true.
     */
    public function init($start = true) {
        App::import('Vendor', 'Facebook', array('file' => 'facebook' . DS .'facebook.php'));
        $this->facebook = new Facebook(array(
            'appId' => $this->settings['appId'],
            'secret' => $this->settings['secret'],
        ));
        
        if ($start) {
            $user = $this->facebook->getUser();
            if ($user) {
                $this->me = $this->api('/me');
                $this->uid = $user;

                $this->controller->set('facebookSession', $this->session);
                $this->controller->set('me', $this->me);
            } else {
                $this->_authRedirect();
            }
        }
    }
    
    /**
     * Retrieve valid access token
     */
    private function _authRedirect() {
        $options = array('scope' => $this->settings['permissions']);
        if (!empty($this->settings['appUrl'])) {
            $options['redirect_uri'] = $this->settings['appUrl'];
        }
        $url = $this->facebook->getLoginUrl($options);
        echo "<script type=\"text/javascript\">top.location.href = '$url';</script>";
        exit;
    }
    
    /**
     * Check if user likes our fanpage
     *
     * @param mixed $pageId Fanpage id
     * @param boolean $cache TRUE if you want to cache your result
     * @return boolean TRUE if user likes our fanpage
     */
    public function isPageFan($pageId = null, $cache = true) {
        if (empty($pageId)) $pageId = $this->settings['pageId'];
        $isFan = false;
        if ($cache) $isFan = Cache::read("isFan" . $this->uid);
        if($isFan === false) {
            $result = $this->api('/' . $this->uid . '/likes/' . $this->settings['pageId']);
            $isFan = (!empty($result['data']) ? true : false);
            if ($cache && $isFan) Cache::write("isFan" . $this->uid, true);
        }
        return $isFan;
    }
    
    /**
     * Get list of user friends who like our fanpage
     *
     * @param mixed $pageId Fanpage id
     * @param boolean $cache TRUE if you want to cache your result
     * @return mixed An array of user friends
     */
    public function getFriendsPageFans($pageId = null, $cache = false) {
        if (empty($pageId)) $pageId = $this->settings['pageId'];
        $friends_fans = false;
        if ($cache) $friends_fans = Cache::read("friendsPageFans" . $this->uid);
        if ($friends_fans === false) {
            $query = "SELECT uid FROM page_fan WHERE page_id = " . $this->settings['pageId']
                   . "AND uid IN (SELECT uid2 FROM friend WHERE uid1 = " . $this->uid .")";
            $friends_fans = $this->api(array(
                "method" => "fql.query",
                "query" => $query
            ));
            if ($cache && $friends_fans) Cache::write("friendsPageFans" . $this->uid, $friends_fans);
        }
        return $friends_fans;
    }
    
    /**
     * Get list of user friends who is using current app
     *
     * @return mixed An array of user friends
     */
    public function getFriendsAppUsers() {
        return $this->api(array('method' => 'friends.getAppUsers'));
    }
    
    /**
     * Publish stream to $uid wall. <br />
     * Full example options record below
     * 
     * <code>
     * $options = array(
     *     'message' => 'This is just a test message',
     *     'link' => 'http://www.facebook.com',
     *     'picture' => 'http://static.ak.fbcdn.net/rsrc.php/v1/zK/r/NGGPJRdOdhs.png',
     *     'name' => 'Tets link name',
     *     'caption' => 'Test link caption',
     *     'description' => 'Test link description',
     *     'actions' => '{"name": "View on Google", "link": "http://www.google.com"}',
     *     'privacy' => '{"value": "ALL_FRIENDS"}',
     *     'targeting' => '{"countries":"US","regions":"6,53","locales":"6"}',
     * );
     * </code>
     * 
     * @param mixed $uid Wall owner id
     * @param array $options Record to publish
     * @return boolean TRUE on success, FALSE on failure
     */
    public function streamPublish($uid = null, $options = array()) {
        if (empty($uid)) $uid = $this->_testAccountId;
        $result = $this->api("/{$uid}/feed", 'POST', $options);
        return (!empty($result['id']) ? true : false);
    }
    
    /**
     * Publish photo to facebook photo album
     *
     * @param string $src Photo to publish
     * @param mixed $album Album name, album id or null for default app album
     * @return mixed FB Photo ID or false on error
     */
    public function photoPublish($src, $album = null) {
        $this->setFileUploadSupport(true);
        if (is_null($album)) {
            $result = $this->api('/me/photos', 'POST', array(
                'source' => "@" . $src
            ));
        } elseif (is_numeric($album)) {
            $result = $this->api('/' . $album . '/photos', 'POST', array(
                'source' => "@" . $src
            ));
        } else {
            $album_name = strval($album);
            $album_id = null;
            $albums = $this->api('/me/albums');
            foreach ($albums['data'] as $album) {
                if ($album['name'] == $album_name) {
                    $album_id = $album['id'];
                    break;
                }
            }
            if ($album_id == null) {
                $result = $this->api('/me/albums', 'POST', array(
                    'name' => $album_name
                ));
                if (!empty($result['id'])) {
                    $album_id = $result['id'];
                }
            }
            $result = $album_id ? $this->api('/' . $album_id . '/photos', 'POST', array(
                'source' => "@" . $src
            )) : false;
        }
        if (!empty($result['id'])) {
            return $result['id'];
        } else {
            return false;
        }
    }
    
    /**
     * Get user friends list
     *
     * @param boolean $cache TRUE if you want to cache your result
     * @return mixed 
     */
    public function getFriends($cache = true) {
        if ($cache) $friends = Cache::read("friends" . $this->uid);
        if ($friends === false) {
            $friends = $this->api('/me/friends');
            if ($cache && $friends) Cache::write("friends" . $this->uid, $friends);
        }
        return $friends;
    }
    
    /**
     * Get page posts list
     *
     * @param mixed $pageId Facebook Fanpage ID
     * @param array $options Available keys are: "limit", "since", "until"
     * @return array An array of page posts 
     */
    public function getPagePosts($pageId = null, $options = array()) {
        if (empty($pageId)) $pageId = $this->settings['pageId'];
        $posts = $this->api("/$pageId/posts", $options);
        return $posts;
    }
    
    /**
     * Get page feed
     *
     * @param mixed $pageId Facebook Fanpage ID
     * @param array $options Available keys are: "limit", "since", "until"
     * @return array An array of page feed entries
     */
    public function getPageFeed($pageId = null, $options = array()) {
        if (empty($pageId)) $pageId = $this->settings['pageId'];
        $posts = $this->api("/$pageId/feed", $options);
        return $posts;
    }
    
    /**
     * Get list of fanpages, which current user administrates
     *
     * @param boolean $cache TRUE if you want to cache your result
     * @return array List of fanpages
     */
    public function getUserPages($cache = false) {
        $pages = false;
        if ($cache) $pages = Cache::read("pages" . $this->uid);
        if ($pages === false) {
            $pages = $this->api("/me/accounts");
            if ($cache && $pages) Cache::write("pages" . $this->uid, $pages);
        }
        return $pages;
    }
    
    /**
     * Get fanpage insights
     *
     * @param mixed $pageId Facebook Fanpage ID
     * @param string $type Insights category
     * @param boolean $cache TRUE if u want to cache your result
     * @return array List of insights
     */
    public function getInsights($pageId = null, $type = null, $cache = true) {
        if (empty($pageId)) $pageId = $this->settings['pageId'];
        $insights = false;
        if ($cache) $insights = Cache::read("insights" . $pageId . date('Ymd'));
        if ($insights === false) {
            $insights = is_null($type) ? $this->api("/$pageId/insights") : $this->api("/$pageId/insights/$type");
            if ($cache && $insights) Cache::write("insights" . $pageId . date('Ymd'), $insights);
        }
        return $insights;
    }
    
    /**
     * Get filtered fanpage insights
     *
     * @param mixed $pageId Facebook Fanpage ID
     * @param array $filters Available keys are: "type", "since", "until", "period"
     * @return array 
     */
    public function getInsightsFiltered($pageId = null, $filters = array()) {
        if (empty($pageId)) $pageId = $this->settings['pageId'];
        
        $conditions = array();
        $conditions[] = "object_id=$pageId";
        if (!empty($filters['type'])) $conditions[] = "metric='{$filters['type']}'";
        if (!empty($filters['since'])) $conditions[] = "end_time>end_time_date('{$filters['since']}')";
        if (!empty($filters['until'])) $conditions[] = "end_time<end_time_date('{$filters['until']}')";
        if (!empty($filters['period'])) $conditions[] = "period=period('{$filters['period']}')";
        
        $query = "SELECT metric, value, end_time, period FROM insights WHERE " . implode(' AND ', $conditions);
        $insights = $this->api(array(
                    "method" => "fql.query",
                    "query" => $query
                ));
        return $insights;
    }
    
    /**
     * Delete facebook object
     *
     * @param mixed $id Facebook object id
     * @return mixed Operation result
     */
    public function delete($id) {
        return $this->api('/' . $id, 'DELETE');
    }

}
