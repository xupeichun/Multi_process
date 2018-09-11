# Multi_process
Multi process distribution model

采用守护进程模式，监听子进程生命周期，子进程挂掉守护进程可以立马补齐
子进程负责任务处理，从而达到一个任务可以多进程同时处理，加快任务处理速度
例：借助redis作为任务分派容器，子进程消费容器消息。处理场景有：邮件、短信大批量分发
启动：php fabloox.php start -d 
停止：php fabloox.php stop 
