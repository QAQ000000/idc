# 魔方云任务类型最小兼容补丁

适用于财务 `DcimCloud::getCloudStatus` 丢弃云端 `data.task`、`Host::statusDataForClient` 将代理状态统一为 `status/des` 的部署。补丁是供上游维护者审阅的源码差异，简云运行时不会加载或安装它，不连接旧库。

## 字段与边界

- 云状态必须是 `task`，且机器字段 `data.task` 严格等于字符串 `reinstall`，才投影 `data.task_type="reinstall"`。
- 财务客户投影只在 `status=process` 时保留这一枚举。原始任务名、日志、操作编号、供应商地址和凭据均不新增透传。
- 没有类型、未知类型或上游只提供中文描述时，继续调查，不降级为任意忙闲变化识别。
- 类型只识别重装执行，不是唯一操作 ID 或完成回执；本站外的并行重装无法据此完全区分。不增加自动重发或最终系统成功承诺。

本机源码中已有云端 `data.task`（如 suspend/off）的使用依据，但尚未取得真实重装执行时 `task=reinstall` 的样本。此白名单是待部署验证的兼容约定；若真机值不同，先取得可信结构化样本再审查映射，不能猜测别名或回退中文。裸金属进度接口不适用于本批云主机。

## 多层代理与供应商协作

本地副本数据库只读事务查到商品14为 `api_type=zjmf_api`、`upstream_pid=48`，状态先转发到另一套财务；副本库没有现网资源4253，不能把副本数据当成实时部署证明。用户已确认更上层财务由供应商维护，需要其提供字段。SakuraIDC 的客户白名单补丁只能保留收到的类型，无法恢复下一层已丢弃的字段；直接连云的财务负责投影真实机器字段，中间代理逐层保留白名单。不得绕过原账号／资源绑定改为直连云接口。

可转给供应商的最小要求：

> 请在现有 `POST /provision/default`（原资源 id、func=status、is_api=1）的认证与资源归属检查内，读取真实云端结构化执行类型。仅当该资源正在执行重装时返回 `{"status":200,"data":{"status":"process","task_type":"reinstall"}}`，原 des 字段可保留；空闲时返回原 on/off 并去掉 task_type。task_type 必须为字符串，不能用裸金属数字进度、中文提示或发起日志推算。如果能提供可信的操作ID或执行记录，请说明来源、资源归属、生命周期与查询方式；不要生成伪ID或把发起受理当完成。请提供脱敏执行中／空闲样本及实际枚举定义，样本不要包含凭据、域名或其他客户资源。无需新建接口或开放云端凭据。

供应商实际字段值及权威度确认后才适配映射。仅类型仍不是唯一执行ID，执行结束与系统可登录必须分开验收。供应商未支持前，已受理任务保持自动只读调查，无法确认时走原异常核验；本批不能宣称正常自动闭环已完成。

## 副本验证与部署

2026-11-09 对用户指定的 `/www/wwwroot/sakuraidc.cc` 两个文件做字节哈希核对，与本机解密源码相同，文件相互独立。补丁基于当时的**工作区内容**，不是该目录旧 Git HEAD；不要重置或整目录覆盖其中的既有改动。正式站不因本地副本变更自动更新。本轮已在隔离检查后应用到该本地副本，保留原权限和私有原件备份；只将本补丁的9增1删通过索引局部提交为 `9768518`，其余已有工作区差异均保留。正式站未同步。

在不含数据库配置的隔离源码副本先执行（PHP 7.2；替换绝对路径）：

```bash
git -C /path/to/upstream-copy apply --ignore-space-change --check /path/to/structured-task-type.patch
git -C /path/to/upstream-copy apply --ignore-space-change /path/to/structured-task-type.patch
/www/server/php/72/bin/php /path/to/test_structured_task_type.php /path/to/upstream-copy
/www/server/php/72/bin/php -l /path/to/upstream-copy/app/common/logic/DcimCloud.php
/www/server/php/72/bin/php -l /path/to/upstream-copy/app/common/logic/Host.php
```

`--ignore-space-change` 兼容该发行包混合 CRLF/LF 的上下文，应用前须审阅差异；补丁不包含账号或配置。独立测试通过 stub 检查云端→财务→代理投影，不启动 ThinkPHP、不调用网络、不读取数据库，也不能替代真实任务类型验收。

正式同步前由部署方备份、核对两处上下文、运行版本与文件归属，仅同步经审阅的差异，再检查站点健康和进程缓存策略。简云侧需优雅重启对应业务 Worker 以加载驱动，既有任务仍只读；无新增迁移。真实重装须另行授权系统盘清空，不能复用已消费的一次许可。验收时分别记录结构化类型、执行结束后的凭据同步与真实登录；漏观察仍走原人工异常出口。

完整状态机与兼容边界见 [重装契约](../../../../docs/M2_INSTANCE_REINSTALL.md)。
