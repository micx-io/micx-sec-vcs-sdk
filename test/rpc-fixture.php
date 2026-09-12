<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
$c=new AMQPStreamConnection('127.0.0.1',5672,'guest','guest'); $ch=$c->channel();
$ch->exchange_declare('micx.vcs.v1','topic',false,true,false);
$ch->queue_declare('micx.vcs.v1.fixture',false,false,false,true);
$ch->queue_bind('micx.vcs.v1.fixture','micx.vcs.v1','rpc.request');
$ch->basic_consume('micx.vcs.v1.fixture','',false,false,false,false,function(AMQPMessage $m) use ($ch) {
    $r=json_decode($m->getBody(),true,64,JSON_THROW_ON_ERROR);
    $response=['version'=>1,'id'=>$r['id'],'ok'=>true,'result'=>['echo'=>$r['params']]];
    $ch->basic_publish(new AMQPMessage('{}',['correlation_id'=>'unrelated']), '',$m->get('reply_to'));
    $ch->basic_publish(new AMQPMessage(json_encode($response),['correlation_id'=>$r['id']]), '',$m->get('reply_to'));
    $m->ack();
});
file_put_contents(sys_get_temp_dir().'/micx-rpc-ready','ready');
while($ch->is_consuming()) $ch->wait();
