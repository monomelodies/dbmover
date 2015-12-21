#!/usr/bin/env node
"use strict";

let snotty = require('snotty');
let fs = require('fs');
let q = require('q');
let md5 = require('md5');
let config = JSON.parse(fs.readFileSync('dbmover.json', 'utf8'));
let Schema = require('./Schema');
let postfix = md5(new Date()).substring(0, 6);

class Dbmover {

    constructor(driver) {
        this._driver = driver;
        // A list of object names in the current database.
        this.stuff = [];
    }

    run(connectionParams, schemas) {
        let deferred = q.defer();
        let $next = () => {
            if (!schemas.length) {
                this._driver.close();
                deferred.resolve('yay');
            }
            let schema = schemas.shift();
            this.processSchema(schema).then($next, err => {
                snotty.error(err);
            });
        };
        this._driver.connect(connectionParams).then(() => {
            snotty.ok('Connected to ' + connectionParams.database + ' as ' + connectionParams.user);
            $next();
        });
        return deferred.promise;
    }

    processSchema(schemaFile) {
        let deferred = q.defer();
        let sql = fs.readFileSync(schemaFile, 'utf8');
        let schema = new Schema(sql);
        let $next = () => {
            if (!schema.commands.length) {
                console.log('wtf');
                deferred.resolve('done');
            } else {
                command = schema.commands.shift();
                console.log('processing command');
                this.processCommand(command).then(() => {
                    snoffy.info('Command run successfully.');
                    $next();
                }, err => {
                    console.log(err);
                    deferred.reject(err);
                });
            }
        };
        $next();
        return deferred.promise;
    }

    processCommand(command) {
        let comments = Schema.extractComments(command);
        if (comments.length) {
            comments.map(comment => {
                snotty.info(comment);
            });
        }
        command = Schema.loseComments(command);
        console.log(command);
        let deferred = q.defer();
        let type = command.match(/^(\w+)\s*(\w+)\s/);
        if (!type) {
            deferred.reject('Parse error in ' + command + ': first two words need to be of the type COMMAND SOMETHING (e.g. CREATE TABLE).');
        } else {
            let maintype = type[1].toUpperCase();
            let subtype = type[2].toUpperCase();
            console.log(maintype, subtype);
            if (maintype.toLowerCase() in this) {
                return this[maintype.toLowerCase()](subtype, command);
            } else {
                deferred.reject('No such operation: ' + maintype);
            }
        }
        return deferred.promise;
    }

    // Only do this if the row seems to not exist yet.
    insert(subtype, command) {
        let deferred = q.defer();
        deferred.resolve('ok');
        return deferred.promise;
    }

    // Just try and swallow any errors - they probably mean
    // the statement has already been run before.
    alter(subtype, command) {
        return this._driver.query(command).then(() => {
            snotty.ok(command.replace(/\n/g, ' ').substring(0, 50) + '...');
        }, err => {
            snotty.warn(command.replace(/\n/g, ' ').substring(0, 50) + '... failed (' + err + ')');
        });
    }

    create(subtype, command) {
        let name = command.match(/^\w+\s*\w+\s*(\w+)\s/)[1];
        this.stuff.push(name);
        if (subtype == 'TABLE') {
            // If table already exists, only run this if its definition
            // actually changed:
            let tmpname = name + postfix;
            return this._driver.compareAndSwapTables(command, name, tmpname);
        } else {
            return this._driver.query(command);
        }
    }

    drop(subtype, command) {
        return this.passthru(command);
    }

    update(subtype, command) {
        return this.passthru(command);
    }

    ['delete'](subtype, command) {
        return this.passthru(command);
    }

    passthru(command) {
        return this._driver.query(command).then(() => {
            snotty.ok(command.replace(/\n/g, ' ').substring(0, 50) + '...');
        });
    }

    cleanup() {
        let deferred = q.defer();
        this._driver.listTables().then(tables => {
            console.log(tables);
        });
    }

}

process.argv.slice(2).forEach(arg => {
    if (!(arg in config)) {
        snotty.warn('Config for ' + arg + ' not found in Dbmover.json, skipping...');
        return;
    }
    let driverImport = require(config[arg].driver);
    let driver = new driverImport();
    let mover = new Dbmover(driver);
    mover.run(config[arg].connection, config[arg].schemas).then(() => {
        console.log('ok');
    });
});
