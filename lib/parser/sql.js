
"use strict";

var match = undefined;

module.exports = {
    extract: function (sql) {
        // Phase one: create commands
        function exists(name) {
            for (var i = 0; i < commands.length; i++) {
                if (commands[i].name == name) {
                    return true;
                }
            }
            return false;
        };
                
        var commands = [];
        var cmdMatch = /CREATE\s+(OR REPLACE)?\s*(FUNCTION|PROCEDURE|VIEW|TABLE|TRIGGER)\s+(\w+)/gi;
        while (match = cmdMatch.exec(sql)) {
            var command = undefined;
            var re = undefined;
            if (['FUNCTION', 'PROCEDURE', 'TRIGGER'].indexOf(match[2].toUpperCase()) != -1) {
                re = new RegExp(match[0] + '(\\n|.)*?BEGIN(\\n|.)*?END;', 'g');
            } else {
                re = new RegExp(match[0] + '(\\n|.)*?;', 'g');
            }
            var submatch = undefined;
            while (submatch = re.exec(sql)) {
                command = submatch[0];
            }
            if (!exists(match[3])) {
                commands.push({
                    type: match[2],
                    name: match[3],
                    sql: command
                });
            }
        }
        return commands;
    },
    tables: function (sql) {
        var tables = {};
        var tableMatch = /CREATE TABLE (.*?) \(/gi;
        while (match = tableMatch.exec(sql)) {
            tables[match[1]] = true;
        }
        return tables;
    }
};

