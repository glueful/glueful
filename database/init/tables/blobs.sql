CREATE TABLE IF NOT EXISTS blobs (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    url VARCHAR(255) NOT NULL,
    size DOUBLE PRECISION NOT NULL,
    user_id BIGINT NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted')),
    created_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_blobs_user_id ON blobs(user_id);