-- 已存在的 MVP 数据库升级；新库直接使用 schema.sql。执行前备份。
-- MySQL ALTER TABLE 会隐式提交，不能以外层事务包裹整个迁移。
ALTER TABLE instances
    MODIFY start_date DATETIME NULL DEFAULT NULL COMMENT '实际交付起租时间 (pending 未交付时为NULL)',
    MODIFY due_date DATETIME NULL DEFAULT NULL COMMENT '到期时间 (pending 未交付时为NULL)';

-- 尚未交付的 MVP 占位实例不应携带虚假的已开始租期；已交付资产保持原日期。
UPDATE instances SET start_date = NULL, due_date = NULL
WHERE status = 'pending' AND remote_instance_id IS NULL;
