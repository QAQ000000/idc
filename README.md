# JianYun (简云)

> **极简、高扩展的现代化云产品聚合销售系统**
> 面向异构上游代理（智简魔方等）与多形态自营服务（Kangle、PVE、Sub2API、手工VPS）。

## 核心设计特性

- ⚡ **极致极简**：告别旧魔方 178 张表的臃肿泥潭，仅用 8 张核心领域表（全系统共精简收敛为 25 张物理表）支撑 100% 真实业务。
- 🔌 **驱动插件化**：无论是魔方代理、自营虚拟主机还是 AI 额度分发，均统一抽象为 `Provider` 驱动。
- 🛡️ **事务发件箱 (Transactional Outbox)**：杜绝“先调上游接口导致网络超时丢任务”，确保支付、账务与履约百分之百一致。
- 🔄 **双阶段自动续费**：出账与代扣彻底解耦，无论何时充值余额，均能秒级自动代扣续费，杜绝欠费假死停机。
- 📈 **智能升降级与改价神器**：弹性云按天补差（降级 0 元不退款）；后台支持单实例一键改续费价并自动划扣当期差价。
- 🔒 **返佣防套利**：退款与返利实时级联冲正，彻底杜绝自推自买套现。

---

## 架构文档导航

- [协同开发指南与工程宪法](AGENTS.md)
- [系统全景架构设计](docs/ARCHITECTURE.md)
- [契约一：支付、多收、退款与资金账本协议](docs/PAYMENTS_LEDGER.md)
- [契约二：Outbox 幂等、执行租约与未知结果恢复协议](docs/TASKS_OUTBOX.md)
- [契约三：实例生命周期、报价锁定期与快照调度契约](docs/LIFECYCLE_PRICING.md)
- [契约四：生产割接平滑迁移与对账稽核实施规程](docs/MIGRATION_RUNBOOK.md)
- [统一单前端架构设计规范](docs/FRONTEND.md)
- [数据库设计字典与 DDL](docs/DATABASE.md)
- [RESTful API 契约与接口字典](docs/API.md)
- [模块化插件与驱动开发手册](docs/PLUGIN_DEV.md)
- [支付网关聚合与资金安全规范](docs/PAYMENTS.md)
- [模块化多渠道通知中心设计](docs/NOTIFICATIONS.md)
- [模块化验证码中心设计](docs/CAPTCHA.md)
- [精简营销发券与防重审计规范](docs/MARKETING_ENGINE.md)
- [客服工单与管理员内部协同规范](docs/TICKETS.md)
- [云快照与备份自主计划任务调度规范](docs/BACKUP_SCHEDULER.md)
- [推广返佣与提现体系规范](docs/AFFILIATE.md)
- [全栈SEO与长尾流量获客规范](docs/SEO.md)
- [研发路线图与交付里程碑](docs/ROADMAP.md)

---

## 目录结构

```text
/www/wwwroot/jianyun/
├── AGENTS.md                  # Agent 协同开发指南与行为基准 (最高宪法)
├── README.md                  # 项目概览
├── docs/                      # 架构文档、数据字典与路线图
│   ├── ARCHITECTURE.md
│   ├── DATABASE.md
│   └── ROADMAP.md
├── app/                       # 核心业务域代码
│   ├── Controllers/
│   ├── Models/
│   └── Services/
├── plugins/                   # 驱动与插件扩展目录
│   ├── providers/             # 上游服务驱动 (zjmf, easypanel, sub2api, manual)
│   ├── payments/              # 支付网关插件 (alipay, epay, balance)
│   └── notifications/         # 消息通道 (mail, sms, tg)
├── database/                  # 数据库迁移与初始化 SQL
├── scripts/                   # 运维工具与旧库迁移导入脚本
└── config/                    # 系统配置
```
