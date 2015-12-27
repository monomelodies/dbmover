
"use strict";

var fs = require('fs');
var colors = require('colors');
var async = require('async');
/*
var q = require('q');
var md5 = require('md5');
var Schema = require('./Schema');
var postfix = md5(new Date()).substring(0, 6);

class Dbmover {

    constructor(driver) {
        this._driver = driver;
        // A list of object names in the current database.
        this.stuff = [];
    }

    run(connectionParams, schemas) {
        var deferred = q.defer();
        var $next = () => {
            if (!schemas.length) {
                this._driver.close();
                deferred.resolve('yay');
            }
            var schema = schemas.shift();
            this.processSchema(schema).then($next, err => {
                console.log(('[ERROR] '.red) + err);
            });
        };
        this._driver.connect(connectionParams).then(() => {
            console.log(('[OK] '.green) + 'Connected to ' + connectionParams.database + ' as ' + connectionParams.user);
            $next();
        });
        return deferred.promise;
    }

    processSchema(schemaFile) {
        var deferred = q.defer();
        var sql = fs.readFileSync(schemaFile, 'utf8');
        var schema = new Schema(sql);
        var $next = () => {
            if (!schema.commands.length) {
                console.log('wtf');
                deferred.resolve('done');
            } else {
                command = schema.commands.shift();
                console.log('processing command');
                this.processCommand(command).then(
                    () => {
                        snoffy.info('Command run successfully.');
                        $next();
                    },
                    err => {
                        console.log(err);
                        deferred.reject(err);
                    }
                );
            }
        };
        $next();
        return deferred.promise;
    }

    processCommand(command) {
        var comments = Schema.extractComments(command);
        if (comments.length) {
            comments.map(comment => {
                snotty.info(comment);
            });
        }
        command = Schema.loseComments(command);
        console.log(command);
        var deferred = q.defer();
        var type = command.match(/^(\w+)\s*(\w+)\s/);
        if (!type) {
            deferred.reject('Parse error in ' + command + ': first two words need to be of the type COMMAND SOMETHING (e.g. CREATE TABLE).');
        } else {
            var maintype = type[1].toUpperCase();
            var subtype = type[2].toUpperCase();
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
        var deferred = q.defer();
        deferred.resolve('ok');
        return deferred.promise;
    }

    // Just try and swallow any errors - they probably mean
    // the statement has already been run before.
    alter(subtype, command) {
        return this._driver.query(command).then(
            () => {
                console.log(('[OK] '.green) + command.replace(/\n/g, ' ').substring(0, 50) + '...');
            },
            err => {
                console.log(('[WARN] '.yellow) + command.replace(/\n/g, ' ').substring(0, 50) + '... failed (' + err + ')');
            }
        );
    }

    create(subtype, command) {
        var name = command.match(/^\w+\s*\w+\s*(\w+)\s/)[1];
        this.stuff.push(name);
        if (subtype == 'TABLE') {
            // If table already exists, only run this if its definition
            // actually changed:
            var tmpname = name + postfix;
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

    ['devare'](subtype, command) {
        return this.passthru(command);
    }

    passthru(command) {
        return this._driver.query(command).then(() => {
            console.log(('[OK] '.green) + command.replace(/\n/g, ' ').substring(0, 50) + '...');
        });
    }

    cleanup() {
        var deferred = q.defer();
        this._driver.listTables().then(tables => {
            console.log(tables);
        });
    }

}
*/
var args = process.argv.slice(2);
var commands = [
    'migrate',
    'test',
    'propose'
];
if (!args.length || commands.indexOf(args[0]) == -1) {
    console.log(('No valid command (' + commands.join(', ') + ') found.').red);
    process.exit(1);
}
var command = args.shift();
if (!args.length) {
    console.log('No environments given. You need to tell me which database(s) to work on.'.red);
    process.exit(1);
}
if (!fs.existsSync('dbmover.json')) {
    console.log('dbmover.json not found in current directory.'.red);
    process.exit(1);
}
try {
    var config = JSON.parse(fs.readFileSync('dbmover.json', 'utf8'));
} catch (e) {
    console.log(('Invalid dbmover.json config: ' + e).red);
    process.exit(1);
}

for (var i = 0; i < args.length; i++) {
}

module.exports = function () {
    async.forEach(args, function (arg, callback) {
        if (!config[arg]) {
            console.log(('Config for ' + arg + ' not found, skipping...').yellow);
            return;
        }
        try {
            var driver = require(config[arg].driver);
        } catch (e) {
            console.log(('Driver ' + config[arg].driver + ' not found, did you forget to npm install it?').red);
            return callback(e);
        }
        config[arg].driver = driver(config[arg]);
        try {
            require('./commands/' + command)(config[arg], function (err, results) {
                if (err) {
                    console.log(('Error during ' + command + ': ' + err).red);
                } else {
                    console.log(('Finished command ' + command + ' for ' + arg).green);
                }
                config[arg].driver.end();
            });
        } catch (e) {
            console.log(('Command ' + command + ' not found, this is likely an error in the package.').red);
            config[arg].driver.end();
            return callback(e);
        }
    }, function (err) {
        if (!err) {
            process.exit(0);
        } else {
            console.log(('' + err).red);
            process.exit(1);
        }
    });
};

