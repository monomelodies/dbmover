
var fs = require('fs');
var parser = require('../parser/sql');
var async = require('async');
var md5 = require('md5');

module.exports = function (config, callback) {
    var out = [];
    var sql = '';
    config.schemas.map(function (schema) {
        sql += fs.readFileSync(schema, 'UTF-8');
    });
    var queue = [];
    var commands = parser.extract(sql);
    var tables = parser.tables(sql);
    config.driver.objects(function (err, res) {
        var objs = [];
        // "Flatten" the array.
        res.map(function (list) {
            if (list) {
                objs = objs.concat(list);
            }
        });

        function exists(obj) {
            for (var i = 0; i <= objs.length; i++) {
                if (!objs[i]) {
                    continue;
                }
                if (objs[i].name == obj.name && objs[i].type == obj.type) {
                    return true;
                }
            }
            return false;
        };

        objs.map(function (obj) {
            if (obj.type != 'TABLE' || !tables[obj.name]) {
                out = out.concat(obj.drop);
            }
        });

        async.forEach(commands, function (command, callback) {
            if (!exists(command) || command.type != 'TABLE') {
                out.push(command.sql);
                return callback();
            }
            // Creating table: are they identical?
            var postfix = md5(Math.random(0, 100) + (new Date));
            var temptable = command.sql.replace(/CREATE TABLE (\w+)/i, 'CREATE TEMPORARY TABLE $1_' + postfix);
            config.driver.query(temptable, function (err, res) {
                if (err) return callback(err);
                config.driver.query("SHOW CREATE TABLE " + command.name, function (err, res) {
                    if (err) return callback(err);
                    var existing = '';
                    for (var key in res[0]) {
                        existing = res[0][key];
                    }
                    config.driver.query("SHOW CREATE TABLE " + command.name + '_' + postfix, function (err, res) {
                        if (err) return callback(err);
                        var proposed = '';
                        for (var key in res[0]) {
                            proposed = res[0][key];
                        }
                        proposed = proposed.replace(/TEMPORARY /, '').replace('_' + postfix, '');
                        if (existing == proposed) {
                            return callback();
                        } else {
                            console.log(existing);
                            console.log(proposed);
                            config.driver.query("SELECT * FROM " + command.name + " LIMIT 1", function (err, res) {
                                if (err) return callback(err);
                                if (!res.length) {
                                    for (var i = 0; i < objs.length; i++) {
                                        if (objs[i].name == command.name) {
                                            out = out.concat(objs[i].drop);
                                            break;
                                        }
                                    }
                                    out.push(command.sql);
                                    return callback();
                                } else {
                                    for (var i = 0; i < objs.length; i++) {
                                        if (objs[i].name == command.name) {
                                            objs[i].drop.map(function (drop) {
                                                if (!drop.match(/^DROP TABLE/)) {
                                                    out.push(drop);
                                                }
                                            });
                                        }
                                    }
                                    out.push("ALTER TABLE " + command.name + " RENAME TO " + command.name + "_" + postfix);
                                    out.push(command.sql);
                                    out.push("INSERT INTO " + command.name + " SELECT * FROM " + command.name + "_" + postfix);
                                    out.push("DROP TABLE " + command.name + "_" + postfix);
                                    return callback();
                                }
                            });
                        }
                    });
                });
            });
        }, function (err) {
            if (err) return callback(err);
            callback(null, out.filter(function (value, index, self) {
                return self.indexOf(value) === index;
            }));
        });
    });
};

