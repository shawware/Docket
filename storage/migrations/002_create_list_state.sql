-- Copyright © 2026 shawware.com.au

CREATE TABLE list_state (
    channel_id  VARCHAR(32) NOT NULL,
    message_ts  VARCHAR(32) NOT NULL,
    PRIMARY KEY (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
