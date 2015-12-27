
var analyse = require('../operations/analyse');
var async = require('async');
var md5 = require('md5');

module.exports = function (config, callback) {
    analyse(config, function (err, out) {
        if (err) return callback(err);
        var queue = [];
        var postfix = md5(Math.random(0, 100) + (new Date));
        out.map(function (command) {
            command = command.replace(/\[POSTFIX\]/, postfix);
            queue.push(function (callback) {
                if (command.match(/\n/) || command.length > 50) {
                    console.log((command.split(/\n/)[0].substring(0, 50) + '...').blue);
                } else {
                    console.log(command.blue);
                }
                config.driver.query(command, function (err, res) {
                    if (err) return callback(err);
                    callback();
                });
            });
        });
        console.log('Starting migration...'.green);
        async.series(queue, function (err, res) {
            if (err) return callback(err);
            console.log('All done!'.green);
            callback();
        });
    });
};

