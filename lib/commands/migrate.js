
var analyse = require('./analyse');
var async = require('async');

module.exports = function (config, callback) {
    analyse(config, function (err, out) {
        if (err) return callback(err);
        var queue = [];
        out.map(function (command) {
            queue.push(function (callback) {
                if (command.match(/\n/)) {
                    console.log((command.split(/\n/)[0] + '...').blue);
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

