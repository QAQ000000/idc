-- 已存在数据库升级；新库使用 schema.sql。执行前备份并暂停快照调度与相关 Worker。
-- 确认历史快照任务已裁决，按本地台账 ID 核对清理任务归属后方可恢复调度。
-- MySQL DDL 隐式提交；此迁移只调整索引/可空性，不调用上游或改写历史台账。
ALTER TABLE instance_snapshots
    MODIFY remote_snapshot_id VARCHAR(100) NULL COMMENT '上游实例内该类型的快照/备份标识，创建未决时为NULL；清理使用本地台账ID',
    DROP INDEX uk_remote_snapshot_id,
    ADD UNIQUE KEY uk_instance_type_remote_snapshot (instance_id, type, remote_snapshot_id);
