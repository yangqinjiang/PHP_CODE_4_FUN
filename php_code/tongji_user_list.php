<?php
/**
 * 统计在线用户的列表,
 * 使用Redis bitmap
 * 以下是示例代码
 */
$user_count = 1000;
 $max_day = 0;
//活跃用户数量
function active_user()
{

    $raw_current_date = I('date/s',date('Ymd'));
    $current_date = str_replace(['-'],[''],$raw_current_date);
    $this->assign('current_date',$current_date);
    $this->assign('raw_current_date',$raw_current_date);
    //多少天,默认7天
    $_raw_current_day = I('day/d',7);
    $raw_current_day = $_raw_current_day ? $_raw_current_day : 7;
    if($_raw_current_day > 7){
        $this->error('时间跨度不能超过7天~~');
    }
    $this->assign('current_day',$raw_current_day);
    $this->assign('raw_current_day',$raw_current_day);

    $this->current_version = $current_version = I('version/s','');
    //版本列表
    $this->version_list = C('CENGFAN7_VERSION_LIST',NULL,[]);

    list($CENGFAN7_ON_LINE, $host, $pwd) = $this->getRedisConnectInfo();
    $redis = new \Redis();

    $redis->connect($host);
    $redis->auth($pwd);

    //当一个用户上线时， 我们就使用 SETBIT 命令， 将这个用户对应的二进制位设置为 1 ：
    $redis->select($CENGFAN7_ON_LINE ? 1 : 0);

    $prod_test_prefix = $CENGFAN7_ON_LINE ? 'prod:' : 'test:';

    $prefix = $current_version.':'.$prod_test_prefix;

    $key_prefix = $prefix.'online_users_day_';



    $this->rquest_url = $_SERVER['REQUEST_URI'];
    if(IS_AJAX){
        $this->user_count = M('diners')->order('id desc')->limit(1)->getField('id');
        $this->max_day = $raw_current_day;
        $data = [];
        $daytime = strtotime($current_date);
        for ($i=1;$i<=$raw_current_day;$i++){

            $day_key = date('Y-m-d',$daytime-86400*($i-1));

            $data[] =  ['name'=>$day_key,'value'=>$this->activeXday($redis,$i,$key_prefix,$daytime)];
        }
        $redis->close();
        $this->ajaxReturn($data);
    }else{

        $redis->close();
    }

    $this->display();
}
 function activeXday( \Redis &$redis,$day=7,$key_prefix='',$end_time=0){


    //7天活跃用户数
    $weekStart = $end_time - ($day -1)*86400;

    $retKeyAnd = 'destKey'.uniqid('and');
    $retKeyOR = 'destKey'.uniqid('or');


    for($i = $weekStart; $i<=$end_time ; $i += 86400) {
        $_pre_key = $key_prefix.date('Ymd', $i);

        //7天连续登录用户数
        if($redis->get($retKeyAnd)) {
            $redis->bitOp('AND', $retKeyAnd, $retKeyAnd, $_pre_key);
        }
        else {
            $redis->set($retKeyAnd, $redis->get($_pre_key),600);
        }

        //7天活跃用户数
        if($redis->get($retKeyOR)) {
            $redis->bitOp('OR', $retKeyOR, $retKeyOR, $_pre_key);
        }else {
            $redis->set($retKeyOR, $redis->get($_pre_key),600);

        }

    }


    //只计算最大的天数
    if($day == $this->max_day){

        //计算活跃用户的用户UID
        $or_login_user = $this->parseUserList($redis, $retKeyOR);
        //计算活跃用户和连续登录的用户UID
        $and_login_user = $this->parseUserList($redis, $retKeyAnd);

    }else{
        $or_login_user = $and_login_user = [];
    }

    return [
        'day'=>$day,'max_day'=>$this->max_day,'user_count'=>$this->user_count,
        'keyOR'=>$retKeyOR,'keyAnd'=>$retKeyAnd,
        'destDayOr'=>$redis->bitcount($retKeyOR),
        'destDayAnd'=>$redis->bitcount($retKeyAnd),
        'or_login_user'=>$or_login_user,
        'and_login_user'=>$and_login_user];
}
/**
 * @param \Redis $redis
 * @param $key
 * @param $or_login_user
 * @return array
 */
function parseUserList(\Redis &$redis, $key)
{
    $bitmap = $this->bitmap_human($redis->get($key));
    $bitmap_length = strlen($bitmap);
    $bitSet = [];

    for ($i = 0; $i < $bitmap_length; $i++) {
        if (1 == $bitmap[$i]) {
            $bitSet[] = $i;
        }
    }

    if(empty($bitSet)){
        return [];
    }

    $login_user = M('diners')->cache(600)->field('id,phone,nickname')->where(['id'=>['in',$bitSet]])->select();

    return $login_user;
}

//记录用户UID
 function login_redis_bitmap($uid,$params)
{
    //使用 Redis 统计在线用户人数
    //http://www.open-open.com/lib/view/open1471683474268.html
    // 在此  方案 4 ：使用位图（bitmap）

    $redis_config = tpCache('redis');
    if(empty($redis_config)){
        Log::record("redis数据库的链接信息不正确");
        sys_exception("redis数据库的链接信息不正确");
        return false;

    }
    $_version = isset($params['_version']) ? $params['_version'] : 'non-version';

    //检查版本号
    //cengfan7.com_web_v1.3.2 打壳版本
    $version_list = C('CENGFAN7_VERSION_LIST',NULL,[]);
    if(!in_array($_version,$version_list)){
        Log::record('版本号错误');
        return false;
    }

    $CENGFAN7_ON_LINE = CENGFAN7_ON_LINE;
    try {
        $redis = new \Redis();

        $host = isset($redis_config['host']) ? $redis_config['host'] : '127.0.0.1';
        $pwd = isset($redis_config['auth']) ? $redis_config['auth'] : '';

        $redis->connect($host);
        $redis->auth($pwd);
        //当一个用户上线时， 我们就使用 SETBIT 命令， 将这个用户对应的二进制位设置为 1 ：
        $redis->select($CENGFAN7_ON_LINE ? 1 : 0);

        $redis->sAdd('version_set',$_version);

        $prefix = $CENGFAN7_ON_LINE ? 'prod:' : 'test:';

        $prefix = $_version . ':' . $prefix;//加入版本号

        $redis->setOption(\Redis::OPT_PREFIX, $prefix);
        //TODO:每天
        $day_key = 'online_users_day_'.date('Ymd',NOW_TIME);

        $redis->setBit($day_key,$uid,1);
        //TODO:每小时
        $hour_key = 'online_users_hour_'.date('YmdH',NOW_TIME);
        $redis->setBit($hour_key,$uid,1);

        $redis->close();

    } catch (\Exception $e) {
        $err = 'Redis connect err'.$e->getMessage();
        Log::record($err);
        sys_exception("Redis链接错误!!!");
        Cengfan7ErrorLogModel::record("Redis链接错误!!!");
    }
}

