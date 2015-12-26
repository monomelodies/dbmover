
"use strict";

var mysql = require('mysql');
var drop = require('./operations/drop-table');
var async = require('async');

module.exports = function (config) {

    var that = {};
    var parts = config.dsn.match(/^mysql:\/\/(\w+):(.*?)@(.*?)\/(.*?)$/);
    var objs = [];
    var database = parts[4];
    var connection = mysql.createConnection(config.dsn);

    that.query = function (sql, params, callback) {
        return connection.query(sql, params, callback);
    };

    that.end = function (callback) {
        return connection.end(callback);
    };

    that.objects = function (callback) {
        objs = [];
        async.series(
            [that.views, that.routines, that.tables],
            callback
        );
    };

    that.tables = function (callback) {
        connection.query("SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [database], function (err, tables) {
            if (err) return callback(err);
            async.forEach(tables, function (table, callback) {
                var tbl = {name: table.TABLE_NAME, type: 'TABLE', drop: undefined};
                objs.push(tbl);
                drop(connection, database, table.TABLE_NAME, function (err, statements) {
                    if (err) return callback(err);
                    tbl.drop = statements;
                    callback(null, objs);
                });
            }, function (err, res) {
                return callback(err, res);
            });
        });
    };

    that.views = function (callback) {
        connection.query("SELECT * FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = ?", [database], function (err, views) {
            if (err) return callback(err);
            views.map(function (view) {
                objs.push({name: view.TABLE_NAME, type: 'VIEW', drop: ['DROP VIEW ' + view.TABLE_NAME + ';']});
            });
            return callback(null, objs);
        });
    };

    that.routines = function (callback) {
        connection.query("SELECT * FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA = ?", [database], function (err, routines) {
            if (err) return callback(err);
            routines.map(function (routine) {
                objs.push({
                    name: routine.ROUTINE_NAME,
                    type: routine.ROUTINE_TYPE,
                    drop: ['DROP ' + routine.ROUTINE_TYPE + ' ' + routine.ROUTINE_NAME + ';']
                });
            });
            return callback(null, objs);
        });
    };

    that.sqlToTableDefinition = function (sql) {
        var def = {columns: [], indices: [], constraints: []};
        console.log(sql);
        var lines = sql.match(/\(((\n|.)*)\)/)[1].split(/,/);
        var isNotColumn = /^(CONSTRAINT|PRIMARY|INDEX|UNIQUE)/i;

        lines.map(function (line) {
            if (!line.trim().length) {
                return;
            }
            var nullAllowed = true;
            var match = undefined;
            line = line.trim().replace(/\s*,\s*/g, ',');
            if (match = isNotColumn.exec(line)) {
                switch (match[1]) {
                    
                }
            } else {
                var parts = line.trim().split(/\s+/);
                var col = {name: parts.shift(), type: parts.shift().toUpperCase(), nullable: true, 'default': undefined, serial: false};
                try {
                if (parts[0].match(/UNSIGNED/i)) {
                    col.type += ' ' + parts.shift();
                }
                } catch (e) {
                console.log(parts);
                }
                if (col.type == 'SERIAL') {
                    col.serial = true;
                }
                while (parts.length) {
                    var work = parts.shift();
                    switch (work.toUpperCase()) {
                        case 'PRIMARY':
                            def.constraints.push({type: 'PRIMARY KEY', column: col.name});
                            parts.shift(); // KEY
                            break;
                        case 'AUTO_INCREMENT':
                            col.serial = true;
                            break;
                        case 'NOT':
                            col.nullable = false;
                            parts.shift(); // NULL
                            break;
                        case 'DEFAULT':
                            var value = parts.shift();
                            if (value == 'NULL') {
                                col.nullable = true;
                            } else {
                                col['default'] = value;
                            }
                            break;
                        case 'REFERENCES':
                            
                            break;
                        default: console.log(('Unrecognised: ' + work).yellow);
                    }
                }
                def.columns.push(col);
            }
        });
        console.log(def);
    };

    return that;

};

