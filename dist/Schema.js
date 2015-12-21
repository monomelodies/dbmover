"use strict";

let snotty = require('snotty');
let colors = require('colors');

class Schema {

    constructor(sql) {
        // Lose all comments.
        sql = sql.replace(/\/\*(.|\n)*?\*\//, '');
        // In compound statements (e.g. procedure declarations) force an alternate
        // delimiter (/**/) so the command split works seamlessly:
        sql = sql.replace(/BEGIN(.|\n)*?END;\n/g, match => {
            return match.replace(/;\n/g, '/**/\n').replace(/END\/\*\*\/\n$/g, 'END;\n');
        });
        // Trim and split into separate commands.
        this.commands = sql.replace(/^\s*/, '').replace(/\s*$/, '').split(/;\n/g);
        snotty.info('Parsed schema, commands setup.');
    }
}

Schema.extractComments = function (sql) {
    let comments = sql.match(/--(.*?)\n/g);
    let ret = [];
    if (comments && comments.length) {
        comments.map(comment => {
            ret.push(comment.replace(/--\s*/, '').replace(/\s*$/g, '').magenta);
        });
    }
    return ret;
};

Schema.loseComments = function (sql) {
    return sql.replace(/--(.*?)\n/g, '');
};

module.exports = Schema;
