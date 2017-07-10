use workman-gatewayworker to make a websocket connection for computing user`s online time.

前段模板通信调用，注意拥有该代码的脚本不与此gatewayworker可开启的websocket后台同属一个进程，所以想要通信的前端脚本应该在另外一个站点目录下，是属于客户端与websocket进程之间的通信，而非客户端与自身web服务器的通信，详情请百度gatewayworker或workman与thinkphp等框架配合使用的方法。
var url = 'ws://127.0.0.1';
            var ws = new WebSocket(url);
            if(ws) {
                console.log(ws);
                //执行客户端本身的心跳响应,向websocket服务传送用户id
                setInterval(heart,3000);
                function heart(){
                    //循环获取时需要再次判断
                    var user_id = "{{session('u_id')}}";
                    if(user_id){
                        ws.send(user_id);
                    }else{
                        ws.send("stop");
                    }
                }
