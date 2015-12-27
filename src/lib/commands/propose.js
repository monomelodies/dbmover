
var analyse = require('../operations/analyse');

module.exports = function (config, callback) {
    analyse(config, function (err, out) {
        if (err) return callback(err);
        console.log('Proposing the following changes:'.green);
        console.log(out.join("\n").blue);
        callback();
    });
};

