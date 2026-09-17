-- Copyright © 2026 shawware.com.au

CREATE TABLE tasks (
    id                 INT AUTO_INCREMENT NOT NULL,
    channel_id         VARCHAR(32) NOT NULL,
    title              VARCHAR(500) NOT NULL,
    assignee_user_id   VARCHAR(32) NULL,
    important          TINYINT(1) NOT NULL DEFAULT 0,
    priority           INT NOT NULL,
    due_date           DATE NULL,
    status             ENUM('open', 'done') NOT NULL DEFAULT 'open',
    created_by         VARCHAR(32) NOT NULL,
    created_at         DATETIME NOT NULL,
    completed_at       DATETIME NULL,
    source_permalink   VARCHAR(2000) NULL,
    PRIMARY KEY (id),
    INDEX idx_tasks_channel_priority (channel_id, priority),
    INDEX idx_tasks_assignee_status (assignee_user_id, status),
    INDEX idx_tasks_channel_status (channel_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
