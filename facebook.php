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
        'appId' => 'YOUR_APP_ID',
        'secret' => 'YOUR_APP_SECRET',
        'permissions' => 'COMMA_SEPARATED_PERMS_LIST',
        'pageId' => 'PAGE_ID',
    );
    
    /**
     * Facebook user id for tests
     *
     * @var string
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
    
    private function _authRedirect() {
        $url = $this->facebook->getLoginUrl(array(
            'scope' => $this->settings['permissions'],
            'redirect_uri' => !empty($this->settings['appUrl']) ? $this->settings['appUrl'] : null
        ));
        echo "<script type=\"text/javascript\">top.location.href = '$url';</script>";
        exit;
    }
    
    public function isPageFan($cache = true) {
        $isFan = false;
        if ($cache) $isFan = Cache::read("isFan" . $this->uid);
        if($isFan === false) {
            $isFan = $this->api(array(
                "method" => "pages.isFan",
                "page_id" => $this->settings['pageId'],
                "uid" => $this->uid,
            ));
            if ($cache && $isFan) Cache::write("isFan" . $this->uid, true);
        }
        return $isFan;
    }
    
    public function getFriendsPageFans($cache = false) {
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
    
    public function getFriendsAppUsers() {
        return $this->api(array('method' => 'friends.getAppUsers'));
    }
    
    public function streamPublish($uid = null, $options = array()) {
        if (empty($uid)) $uid = $this->_testAccountId;
//        $defaults = array(
//            'message' => 'This is just a test message',
//            'link' => 'http://www.facebook.com',
//            'picture' => 'http://static.ak.fbcdn.net/rsrc.php/v1/zK/r/NGGPJRdOdhs.png',
//            'name' => 'Tets link name',
//            'caption' => 'Test link caption',
//            'description' => 'Test link description',
//            'actions' => '{"name": "View on Google", "link": "http://www.google.com"}',
//            'privacy' => '{"value": "ALL_FRIENDS"}',
//            'targeting' => '{"countries":"US","regions":"6,53","locales":"6"}',
//            
//        );
//        $options = array_merge($defaults, $options);
        $result = $this->api("/{$uid}/feed", 'POST', $options);
        return true;
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
        } elseif (is_int($album)) {
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
    
    public function getFriends($cache = true) {
        if ($cache) $friends = Cache::read("friends" . $this->uid);
        if ($friends === false) {
            $friends = $this->api('/me/friends');
            if ($cache && $friends) Cache::write("friends" . $this->uid, $friends);
        }
        return $friends;
    }
    
    public function getPagePosts($pageId = null, $options = array()) {
        if (empty($pageId)) $pageId = $this->settings['pageId'];
        $posts = $this->api("/$pageId/posts", $options);
        return $posts;
    }
    
    public function getPageFeed($pageId = null, $offset=0, $limit = 10) {
        if (empty($pageId)) $pageId = $this->settings['pageId'];
        $posts = $this->api("/$pageId/feed", array('since' => $offset, 'limit' => $limit));
        return $posts;
    }
    
    public function getUserPages($cache = true) {
        $pages = false;
        if ($cache) $pages = Cache::read("pages" . $this->uid);
        if ($pages === false) {
            $pages = $this->api("/me/accounts");
            if ($cache && $pages) Cache::write("pages" . $this->uid, $pages);
        }
        return $pages;
    }
    
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
    
    public function getInsightsFull($pageId = null, $filters = array()) {
        if (empty($pageId)) $pageId = $this->settings['pageId'];
        
        $conditions = array();
        $conditions[] = "object_id=$pageId";
        if (!empty($filters['type'])) $conditions[] = "metric='{$filters['type']}'";
        if (!empty($filters['from_date'])) $conditions[] = "end_time>end_time_date('{$filters['from_date']['year']}-{$filters['from_date']['month']}-{$filters['from_date']['day']}')";
        if (!empty($filters['to_date'])) $conditions[] = "end_time<end_time_date('{$filters['to_date']['year']}-{$filters['to_date']['month']}-{$filters['to_date']['day']}')";
        if (!empty($filters['period'])) $conditions[] = "period=period('{$filters['period']}')";
        
        $query = "SELECT metric, value, end_time, period FROM insights WHERE " . implode(' AND ', $conditions);
        $insights = $this->api(array(
                    "method" => "fql.query",
                    "query" => $query
                ));
        return $insights;
    }
    
    public function delete($id) {
        return $this->api('/' . $id, 'DELETE');
    }

}
