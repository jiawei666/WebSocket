<?php
function build($msg) {//封装数据
    $msg = json_encode($msg);
$frame = [];
$frame[0] = '81';
$len = strlen($msg);
if ($len < 126) {
    $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
} else if ($len < 65025) {
    $s = dechex($len);
    $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
} else {
    $s = dechex($len);
    $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
}

$data = '';
$l = strlen($msg);
for ($i = 0; $i < $l; $i++) {
    $data .= dechex(ord($msg{$i}));
}
$frame[2] = $data;

$data = implode('', $frame);

return pack("H*", $data);
}

function parse($buffer) {//解析数据
    $decoded = '';
    $len = ord($buffer[1]) & 127;
    if ($len === 126) {
        $masks = substr($buffer, 4, 4);
        $data = substr($buffer, 8);
    } else if ($len === 127) {
        $masks = substr($buffer, 10, 4);
        $data = substr($buffer, 14);
    } else {
        $masks = substr($buffer, 2, 4);
        $data = substr($buffer, 6);
    }
    for ($index = 0; $index < strlen($data); $index++) {
        $decoded .= $data[$index] ^ $masks[$index % 4];
    }
    return json_decode($decoded, true);
}

function radioMsg($socketArr,$msg){
    $msg = build($msg);
    foreach($socketArr as $key => $socket){
        if($key=='0'){
            continue;
        }
        socket_write($socket['resource'],$msg,strlen($msg));
    }
}


function handshake($socket,$buffer){
    $line_with_key = substr($buffer, strpos($buffer, 'Sec-WebSocket-Key:') + 18);
    $key = trim(substr($line_with_key, 0, strpos($line_with_key, "\r\n")));

    // 生成升级密匙,并拼接websocket升级头
    $upgrade_key = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));// 升级key的算法
    $upgrade_message = "HTTP/1.1 101 Switching Protocols\r\n";
    $upgrade_message .= "Upgrade: websocket\r\n";
    $upgrade_message .= "Sec-WebSocket-Version: 13\r\n";
    $upgrade_message .= "Connection: Upgrade\r\n";
    $upgrade_message .= "Sec-WebSocket-Accept:" . $upgrade_key . "\r\n\r\n";
    socket_write($socket, $upgrade_message, strlen($upgrade_message));// 向socket里写入升级信息
    // 向客户端发送握手成功消息,以触发客户端发送用户名动作;
    $msg = [
        'type' => 'handshake',
        'content' => 'done',
    ];
    $msg = build($msg);
    socket_write($socket, $msg, strlen($msg));
}

header("Content-type:text/html;charset=gbk");
$master = socket_create(AF_INET,SOCK_STREAM,SOL_TCP);//创建服务端套接字

//print_r($master);exit;
socket_bind($master,'127.0.0.1','8081');
socket_listen($master,'9');
//socket_close($master);exit;
socket_set_option($master, SOL_SOCKET, SO_REUSEADDR, 1);
$socketArr = [];
$socketArr[0] = ['resource'=>$master];
//$spawn = socket_accept($master);//监听到客户端连接后，返回客户端的套接字,此函数会阻塞脚本，除非有客户端请求连接
//$hs=false;
while(1){
    $write = $excp = [];
    $sockets = array_column($socketArr,'resource');
//    print_r($sockets);echo '<br>';
    //新连接到来时,被监听的端口($master)是活跃的,如果是新数据到来或者客户端关闭链接时,活跃的是对应的客户端socket而不是服务器上被监听的端口($master)
    socket_select($sockets,$wrirt,$excp,null); //非常重要的函数，处于活跃状态（新消息，关闭连接，有新连接时端口活跃，套接字不活跃）的套接字筛选出来
//    print_r($sockets);echo '<br>';
    foreach($sockets as $socket){
        if($socket == $master){//端口活跃，客户端新连接来了
            $spawn = socket_accept($master);//监听到客户端连接后，返回客户端的套接字,此函数会阻塞脚本，除非有客户端请求连接
            if(!$spawn === false){
//                $bytes = @socket_recv($spawn, $buffer, 2048, 0);
//                handshake($spawn,$buffer);
                socket_getpeername($spawn,$ip,$port);
                $socketArr[(int)$spawn] = ['resource'=>$spawn,'ip'=>$ip,'port'=>$port,'handshake'=>false,'uname'=>''];
//                echo 'hs success!<br>';
                continue;
            }else{
                echo 'socket_accept error!<br>';
                break;
            }
        }else{//客户端关闭或者新消息
            $bytes = socket_recv($socket, $buffer, 2048, 0);
//            print_r($buffer);echo '<br>';
//            print_r($socket);echo '<br>';
            if($bytes<9){
                //logout
                $user = $socketArr[(int)$socket]['uname'];
                unset($socketArr[(int)$socket]);
                $num_list_arr = array_column($socketArr,'uname');
                $msg = ['type'=>'logout','content'=>$user,'user_list'=>$num_list_arr];
                radioMsg($socketArr,$msg);//广播消息
                continue;
            }else{
                if(!$socketArr[(int)$socket]['handshake']){
                    handshake($socket,$buffer);
                    $socketArr[(int)$socket]['handshake'] = true;
//                    echo $buffer.'<br>';
                    echo 'hs success<br>';
                    continue;
                }
                $buffer = parse($buffer);
                print_r($buffer);
                if($buffer['type']=='login'){
                    $socketArr[(int)$socket]['uname'] = $buffer['content'];//获取用户名
                    $num_list_arr = array_column($socketArr,'uname');
                    $msg = ['type'=>'login','content'=>$socketArr[(int)$socket]['uname'],'user_list'=>$num_list_arr];
                    radioMsg($socketArr,$msg);//广播消息
                    continue;
                }
                $msg = ['type'=>'user','from'=>$socketArr[(int)$socket]['uname'],'content'=>$buffer['content']];
                radioMsg($socketArr,$msg);//广播消息
            }
        }

    }

//    if(!$hs){
////        $buffer = socket_read($spawn,2048);
//        $bytes = @socket_recv($spawn, $buffer, 2048, 0);
//        //    print($buffer);
//        //    echo '<br>';
//        handshake($spawn,$buffer);
//        $socketArr[] =
//        $hs=true;
//    }else{
//        $Cinfo = socket_read($spawn,2048);
//        $Cinfo = parse($Cinfo);
//        print_r($Cinfo);
//        exit;
//    }
}


//print($spawn);
//print($master);
//$res = socket_close(Resource id #1);
//print_r($res);