
"use strict";

var mysql = require('mysql');
var drop = require('./operations/drop-table');
var async = require('async');

module.exports = function (config) {

    var that = {};
    var parts = config.dsn.match(/^mysql:\/\/(\w+):(.*?)@(.*?)\/(.*?)$/);
    var objs = [];
    var database = config.database = parts[4];
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

    that.create = function (table, callback) {
        connection.query("SHOW CREATE TABLE " + table, callback);
    };

    that.alter = function (table, orig, proposed, callback) {
        orig = orig.split(/\n/);
        proposed = proposed.split(/\n/);
        console.log(difference(proposed, orig));
        console.log(difference(orig, proposed));
        callback();
    };

    return that;

};

function difference(a1, a2) {
    var result = [];
    for (var i = 0; i < a1.length; i++) {
        if (a2.indexOf(a1[i]) === -1) {
            result.push(a1[i]);
        }
    }
    return result;
};

