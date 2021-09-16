// i took https://github.com/abalabahaha/eris/blob/master/examples/playFile.js and made it worse

const fs = require('fs');
const Eris = require("eris");

const tokenre = /^\$config\['botToken'] = "(.*?)";/mi;
let token = fs.readFileSync("config.php", "utf8").match(tokenre)[1];

// Replace TOKEN with your bot account's token
const bot = new Eris(token);

bot.on("ready", () => { // When the bot is ready
    console.log("Ready!"); // Log "Ready!"
    bot.joinVoiceChannel(process.argv[2]).then((connection) => {
        if (connection.playing) { // Stop playing if the connection is playing something
            connection.stopPlaying();
        }
        connection.play(process.argv[3]); // Play the file and notify the user
        console.log(`Now playing ${process.argv[3]} in channel ${process.argv[2]}`);
        connection.once("end", () => {
            console.log(`Finished`); // Say when the file has finished playing
            bot.leaveVoiceChannel(process.argv[2])
            process.exit(0);
        });
    });
});

bot.on("error", (err) => {
    console.error(err); // or your preferred logger
    process.exit(1);
});

bot.connect(); // Get the bot to connect to Discord
