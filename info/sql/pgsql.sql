
CREATE TABLE dbmover_version (
    id SERIAL,
    filename VARCHAR(255) NOT NULL,
    version VARCHAR(16) NOT NULL,
    checksum VARCHAR(32) NOT NULL,
    datecreated TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
    UNIQUE INDEX(filename, version)
);
CREATE UNIQUE INDEX dbmover_version_filename_version_key ON dbmover(filename, version);

