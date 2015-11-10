
"use strict";

let fs = require('fs');
let mysql = require('mysql');
let md5 = require('md5');
let config = JSON.parse(fs.readFileSync('./Dbmover.json', 'utf8'));

process.argv.slice(2).forEach(arg => {
    if (!(arg in config)) {
        console.log('Config for ' + arg + ' not found in Dbmover.json, skipping...');
        return;
    }
    let connection = mysql.createConnection(config[arg].connection);
    console.log('Connected to ' + config[arg].connection.database + ' as ' + config[arg].connection.user);
    let sql = fs.readFileSync('./schema.sql', 'utf8');
    // Lose all comments.
    sql = sql.replace(/--.*?\n/g, '')
             .replace(/\/\*(.|\n)*?\*\//, '');
    // In compound statements (e.g. procedure declarations) force an alternate
    // delimiter (/**/) so the command split works seamlessly:
    sql = sql.replace(/BEGIN(.|\n)*?END;\n/g, match => {
        return match.replace(/;\n/g, '/**/\n')
                    .replace(/END\/\*\*\/\n$/g, 'END;\n');
    });
    // Trim and split into separate commands.
    let commands = sql.replace(/^\s*/, '')
                      .replace(/\s*$/, '')
                      .split(/;\n/g);

    let postfix = md5(new Date()).substring(0, 6);
    let stuff = [];
    let running = 0;
    for (var i = 0; i < commands.length; i++) {
        let command = commands[i];
        command = command.replace(/^\s*/, '')
                         .replace(/\s*$/, '')
                         .replace(/\/\*\*\/\n/g, ';\n');
        let type = command.match(/^(\w+)\s*(\w+)\s/);
        if (!type) {
            throw 'Parse error in ' + command + ': first two words need to be of the type COMMAND SOMETHING (e.g. CREATE TABLE).';
        }
        let maintype = type[1].toUpperCase();
        let subtype = type[2].toUpperCase();
        switch (maintype) {
            // Only do this if the row seems to not exist yet.
            case 'INSERT':
                return;
            // Just try and swallow any errors - they probably mean
            // the statement has already been run before.
            case 'ALTER':
                connection.query(command, (err, rows, fields) => {
                    if (!err) {
                        console.log('Ok: ' + command.replace(/\n/g, ' ').substring(0, 50) + '...');
                    } else {
                        console.log('Warning: ' + command.replace(/\n/g, ' ').substring(0, 50) + '... failed.');
                    }
                });
                break;
            case 'CREATE':
                let name = command.match(/^\w+\s*\w+\s*(\w+)\s/)[1];
                stuff.push(name);
                if (subtype == 'TABLE') {
                    // If table already exists, only run this if its definition
                    // actually changed:
                    let tmpname = name + postfix;
                    let r = new RegExp('TABLE ' + name, 'i');
                    running++;
                    connection.query('DROP TABLE IF EXISTS ' + tmpname, () => {
                        connection.query(command.replace(r, 'TABLE ' + tmpname), () => {
                            connection.query('DESCRIBE ' + name, (err, origFields) => {
                                if (err || !origFields.length) {
                                    origFields = [];
                                }
                                connection.query('DESCRIBE ' + tmpname, (err, newFields) => {
                                    if (JSON.stringify(origFields) != JSON.stringify(newFields)) {
                                        let sql = 'INSERT INTO ' + tmpname;
                                        let selects = [];
                                        for (let i = 0; i < newFields.length; i++) {
                                            let field = newFields[i].field;
                                            for (let j = 0; j < origFields.length; j++) {
                                                if (origFields[j].field == field) {
                                                    selects.push(field);
                                                }
                                            }
                                        }
                                        sql += '(' + selects.join(', ') + ') SELECT ' + selects.join(', ') + ' FROM ' + name;
                                        console.log(name + ' has changed, copy data...');
                                        connection.query(sql, () => {
                                            connection.query('DROP TABLE ' + name, () => {
                                                connection.query('ALTER TABLE ' + tmpname + ' RENAME TO ' + name, () => {
                                                    drop();
                                                });
                                            });
                                        });
                                    } else {
                                        console.log('Done, dropping ' + tmpname);
                                        drop(tmpname);
                                    }
                                });
                            });
                        });
                    });
                    break;
                }
            // All other cases, just execute:
            case 'DROP':
            case 'UPDATE':
            case 'DELETE':
                connection.query(command, (err, rows, fields) => {
                    if (err) {
                        throw err;
                    }
                    console.log('Ok: ' + command.replace(/\n/g, ' ').substring(0, 50) + '...');
                });
        }
    }

    function finish() {
        console.log('Yay, all up-to-date again.');
        connection.end();
    }

    function drop(table) {
        if (!table) {
            running--;
            if (running <= 0) {
                finish();
            }
            return;
        }
        connection.query('DROP ' + table, () => {
            running--;
            if (running <= 0) {
                finish();
            }
        });
    }

});

