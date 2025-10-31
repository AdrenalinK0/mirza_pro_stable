<?php namespace radiusApi\Modules;

class IBSng
{
    static public $_instance;
    protected $hostname, $username, $password, $port, $timeout;
    protected $isConnected = false;
    protected $loginData = [];
    protected $autoConnect = false;
    protected $cookiePathName = null;
    protected $handler = null;
    protected $agent = 'phpIBSng web Api';

    public function setAutoConnect($autoConnect){ $this->autoConnect = $autoConnect; }
    public function __construct(Array $loginArray){
        if(!extension_loaded('curl')) throw new \Exception("Curl extension needed.");
        libxml_use_internal_errors(true);
        self::$_instance = $this;
        $this->loginData = $loginArray;
        if(!$this->loginData['username'] || !$this->loginData['password'] || !$this->loginData['hostname']) throw new \Exception('IBSng needs correct login info');
        $this->hostname = $loginArray['hostname'];
        $this->username = $loginArray['username'];
        $this->password = $loginArray['password'];
        $this->port = $loginArray['port'];
        $this->timeout = $loginArray['timeout'];
        $this->cookiePathName = sys_get_temp_dir() . '/.' . self::class;
        if($this->autoConnect) $this->connect();
    }
    protected function getCookie(){ return $this->cookiePathName; }
    public function connect(){ if($this->isConnected()) return true; $this->login(); return $this->isConnected = true; }
    public function disconnect(){ if($this->handler){ @unlink($this->getCookie()); @curl_close($this->handler); } }
    public function isConnected(){ return $this->isConnected; }

    public function listGroups(){
        $url = $this->hostname . '/IBSng/admin/group/list_groups.php';
        $output = $this->request($url);
        preg_match_all('/<a href="\/IBSng\/admin\/group\/group_info.php\?group_name=([^"]+)"/',$output,$matches);
        return isset($matches[1]) ? $matches[1] : [];
    }

    public function changeUserGroup($username, $newGroup){
        $uid = $this->_userExists($username);
        if(!$uid) throw new \Exception("user does not exists");
        $url = $this->hostname . '/IBSng/admin/plugins/edit.php';
        $post_data = [
            'user_id'=>$uid,
            'edit_user'=>1,
            'group_name'=>$newGroup,
            'update'=>1,
        ];
        $output = $this->request($url,$post_data,true);
        if(strpos($output,'Successfully')!==false) return true;
        return false;
    }

    public function addUser($username=null,$password=null,$group=null,$credit=null){ return $this->_addUser($group,$username,$password,$credit); }
    public function deleteUser($username){ return $this->_delUser($username); }
    public function listUser(){ return $this->fetchAllUsers(1,100); }
    public function getUser($username){ return $this->infoByUsername($username,true); }

    protected function login(){
        $url = $this->hostname.'/IBSng/admin/';
        $postData=['username'=>$this->username,'password'=>$this->password];
        $output = $this->request($url,$postData,true);
        if(strpos($output,'admin_index')>0) return true;
        throw new \Exception("Can't login to IBSng. Wrong username or password");
    }

    protected function infoByUsername($username,$withPassword=false,$output=null){
        if($output==null) $output = $this->request($this->hostname.'/IBSng/admin/user/user_info.php?normal_username_multi='.$username);
        if(strpos($output,'does not exists')!==false) throw new \Exception("[".$username."] not found");
        $dom = new \DomDocument(); $dom->loadHTML($output); $finder=new \DomXPath($dom);
        $classname='Form_Content_Row_Right_textarea_td_light'; $nodes=$finder->query("//*[contains(@class,'$classname')]"); $lock=trim($nodes->item(0)->nodeValue); $locked=(strpos($lock,'Yes')===false)?'0':'1';
        $classname='Form_Content_Row_Right_userinfo_light'; $nodes=$finder->query("//*[contains(@class,'$classname')]"); $multi=trim($nodes->item(4)->nodeValue); $multi=(strpos($multi,'instances')===false)?0:trim(str_replace('instances','',$multi));
        preg_match('/<a href="\/IBSng\/admin\/group\/group_info.php\?group_name=([^"]+)"/',$output,$group_match); $group_name=$group_match[1]??'Unknown';
        $info=['username'=>$username,'group'=>$group_name,'locked'=>$locked,'multi'=>$multi];
        if($withPassword) $info['password']=$this->getPassword($username);
        return $info;
    }

    protected function getPassword($username,$uid=null){
        if($uid==null) $uid=$this->isUsername($username);
        $url=$this->hostname.'/IBSng/admin/plugins/edit.php'; $postData=['user_id'=>$uid,'edit_user'=>1,'attr_edit_checkbox_2'=>'normal_username'];
        $output=$this->request($url,$postData);
        preg_match('/<input type=text id="password" name="password" value="([^"]+)"/',$output,$matches); return $matches[1]??false;
    }

    public function fetchAllUsers($startUID,$pagePerRequest){
        $totalPages=$this->_csvPages($startUID,$pagePerRequest); if($totalPages<1) return false;
        $usersList=[]; for($p=1;$p<=$totalPages;$p++){ $temp=$this->_csv($startUID,$pagePerRequest,$p); $usersList=array_merge($usersList,is_array($temp)?$temp:[]); }
        return $usersList;
    }

    private function isUsername($username){
        $url=$this->hostname.'/IBSng/admin/user/user_info.php?normal_username_multi='.$username;
        $output=$this->request($url);
        if(strpos($output,'does not exists')!==false) return false;
        preg_match('/change_credit\.php\?user_id=(\d+)"/',$output,$m); return $m[1]??false;
    }

    private function _csvPages($startUid=1,$limit=10){
        $url=$this->hostname.'/IBSng/admin/user/search_user.php';
        $post_data=['rpp'=>$limit,'page'=>1]; $output=$this->request($url,$post_data);
        preg_match('/Page\((\d+)\);/',$output,$m); return $m[1]??1;
    }

    private function _csv($startUid=1,$limit=10,$page=1){
        $url=$this->hostname.'/IBSng/admin/user/search_user.php'; $post_data=['rpp'=>$limit,'page'=>$page];
        $output=$this->request($url,$post_data);
        $lines=explode("\n",$output); $users=[];
        foreach($lines as $line){ if(!$line) continue; $line_spl=explode(",",$line); if(!isset($line_spl[1])||$line_spl[0]=='User ID') continue;
        $users[$line_spl[0]]=['ibsid'=>$line_spl[0],'username'=>$line_spl[1],'credit'=>$line_spl[2],'group'=>$line_spl[3]]; }
        return $users;
    }

    protected function _addUser($group_name,$username,$password,$credit){
        $uid=$this->cr8_uid($group_name,$credit); $url=$this->hostname.'/IBSng/admin/plugins/edit.php?edit_user=1&user_id='.$uid.'&submit_form=1&add=1&count=1&credit=1&owner_name=system&group_name='.$group_name.'&x=35&y=1&edit__normal_username=normal_username';
        $post_data=['target'=>'user','target_id'=>$uid,'update'=>1,'edit_tpl_cs'=>'normal_username','attr_update_method_0'=>'normalAttrs','has_normal_username'=>'t','current_normal_username'=>'','normal_username'=>$username,'password'=>$password,'normal_save_user_add'=>'t','credit'=>$credit];
        $output=$this->request($url,$post_data,true); if(strpos($output,'exist')) throw new \Exception("username already exists"); if(strpos($output,'IBSng/admin/user/user_info.php?user_id_multi')) return true;
    }

    protected function _delUser($username,$logs=true,$audit=true){
        $uid=$this->_userExists($username); if(!$uid) throw new \Exception("user does not exists");
        $url=$this->hostname.'/IBSng/admin/user/del_user.php'; $post_data=['user_id'=>$uid,'delete'=>1,'delete_comment'=>''];
        if($logs) $post_data['delete_connection_logs']='on'; if($audit) $post_data['delete_audit_logs']='on';
        $output=$this->request($url,$post_data,true); return strpos($output,'Successfully')!==false;
    }

    protected function request($url,$postData=[],$header=false){
        if(empty($url)) throw new \Exception('Url empty'); $this->handler=curl_init();
        curl_setopt($this->handler,CURLOPT_CONNECTTIMEOUT,0);
        curl_setopt($this->handler,CURLOPT_TIMEOUT,$this->timeout);
        curl_setopt($this->handler,CURLOPT_URL,$url);
        curl_setopt($this->handler,CURLOPT_PORT,$this->port);
        curl_setopt($this->handler,CURLOPT_POST,true);
        curl_setopt($this->handler,CURLOPT_POSTFIELDS,$postData);
        curl_setopt($this->handler,CURLOPT_HEADER,$header);
        curl_setopt($this->handler,CURLOPT_RETURNTRANSFER,true);
        curl_setopt($this->handler,CURLOPT_USERAGENT,$this->agent);
        curl_setopt($this->handler,CURLOPT_SSL_VERIFYHOST,false);
        curl_setopt($this->handler,CURLOPT_SSL_VERIFYPEER,false);
        curl_setopt($this->handler,CURLOPT_COOKIEFILE,$this->getCookie());
        curl_setopt($this->handler,CURLOPT_COOKIEJAR,$this->getCookie());
        $output=curl_exec($this->handler);
        if(curl_errno($this->handler)!=0) throw new \Exception('Curl Error: '.curl_error($this->handler).$url);
        curl_close($this->handler); return $output;
    }

    private function cr8_uid($group_name,$credit){
        $url=$this->hostname.'/IBSng/admin/user/add_new_users.php';
        $post_data=['submit_form'=>1,'add'=>1,'count'=>1,'credit'=>$credit,'owner_name'=>$this->username,'group_name'=>$group_name,'edit__normal_username'=>1];
        $output=$this->request($url,$post_data,true); preg_match('/user_id=(\d+)&su/',$output,$m); return $m[1]??false;
    }
}