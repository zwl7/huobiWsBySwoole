# huobiWsBySwoole
使用swoole实现火币交易所的实时行情对接，对接火币的币种市场行情时可供参考

注意事项
请提前准备的事项

1.swoole扩展

2.SaberGM包(swoole官方推荐链接webscoket客户端的包) composer.json的配置："swlib/saber": "^1.0"

额外的帮助信息
火币的api文档链接：https://huobiapi.github.io/docs/spot/v1/cn/#5ea2e0cde2-10

setCoinDetail方法是以http方式获取币种的汇率

启动方式
php demo.php


