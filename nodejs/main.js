const moment = require("moment");
const fs = require('fs');
const express = require('express');
const app = express();
const path = require('path');
const { Server } = require('socket.io');

let ctx = {};

const configFile = require("./config.json")
const { Sequelize, Op, DataTypes } = require("sequelize");

// Allowed origins for CORS - UPDATE THIS with your actual domain
const ALLOWED_ORIGINS = [
  configFile.site_url,
  "http://localhost",
  "http://127.0.0.1"
];

// const notificationTemplate = Handlebars.compile(notification.toString());

const listeners = require('./listeners/listeners')

let serverPort
let server
let io

async function loadConfig(ctx) {
  let config = await ctx.wo_config.findAll({ raw: true })
  for (let c of config) {
    ctx.globalconfig[c.name] = c.value
  }
  ctx.globalconfig["site_url"] = configFile.site_url
  ctx.globalconfig['theme_url'] = ctx.globalconfig["site_url"] + '/themes/' + ctx.globalconfig['theme']

  ctx.globalconfig["s3_site_url"]         = "https://test.s3.amazonaws.com";
  if (ctx.globalconfig["bucket_name"] && ctx.globalconfig["bucket_name"] != '') {
      ctx.globalconfig["s3_site_url"] = "https://"+ctx.globalconfig["bucket_name"]+".s3.amazonaws.com";
  }
  ctx.globalconfig["s3_site_url_2"]          = "https://test.s3.amazonaws.com";
  if (ctx.globalconfig["bucket_name_2"] && ctx.globalconfig["bucket_name_2"] != '') {
      ctx.globalconfig["s3_site_url_2"] = "https://"+ctx.globalconfig["bucket_name_2"]+".s3.amazonaws.com";
  }
  var endpoint_url = ctx.globalconfig['ftp_endpoint']; 
  ctx.globalconfig['ftp_endpoint'] = endpoint_url.replace('https://', '');

  // if (ctx.globalconfig["redis"] === "Y") {
  //   const redisAdapter = require('socket.io-redis');
  //   io.adapter(redisAdapter({ host: 'localhost', port: ctx.globalconfig["redis_port"] }));
  // }


  if (ctx.globalconfig["nodejs_ssl"] == 1) {
    var https = require('https');
    var options = {
      key: fs.readFileSync(path.resolve(__dirname, ctx.globalconfig["nodejs_key_path"])),
      cert: fs.readFileSync(path.resolve(__dirname, ctx.globalconfig["nodejs_cert_path"]))
    };
    serverPort = ctx.globalconfig["nodejs_ssl_port"];
    server = https.createServer(options, app);
  } else {
    serverPort = ctx.globalconfig["nodejs_port"];
    server = require('http').createServer(app);
  }

}


async function loadLangs(ctx) {
  let langs = await ctx.wo_langs.findAll({ raw: true })
  for (let c of langs) {
    ctx.globallangs[c.lang_key] = c.english
  }
}


async function init() {
  var sequelize = new Sequelize(configFile.sql_db_name, configFile.sql_db_user, configFile.sql_db_pass, {
    host: configFile.sql_db_host,
    dialect: "mysql",
    logging: function () {},
    pool: {
        max: 20,
        min: 0,
        idle: 10000
    }
  });



  ctx.wo_messages = require("./models/wo_messages")(sequelize, DataTypes)
  ctx.wo_userschat = require("./models/wo_userschat")(sequelize, DataTypes)
  ctx.wo_users = require("./models/wo_users")(sequelize, DataTypes)
  ctx.wo_notification = require("./models/wo_notifications")(sequelize, DataTypes)
  ctx.wo_groupchat = require("./models/wo_groupchat")(sequelize, DataTypes)
  ctx.wo_groupchatusers = require("./models/wo_groupchatusers")(sequelize, DataTypes)
  ctx.wo_videocalls = require("./models/wo_videocalles")(sequelize, DataTypes)
  ctx.wo_audiocalls = require("./models/wo_audiocalls")(sequelize, DataTypes)
  ctx.wo_appssessions = require("./models/wo_appssessions")(sequelize, DataTypes)
  ctx.wo_langs = require("./models/wo_langs")(sequelize, DataTypes)
  ctx.wo_config = require("./models/wo_config")(sequelize, DataTypes)
  ctx.wo_blocks = require("./models/wo_blocks")(sequelize, DataTypes)
  ctx.wo_followers = require("./models/wo_followers")(sequelize, DataTypes)
  ctx.wo_hashtags = require("./models/wo_hashtags")(sequelize, DataTypes)
  ctx.wo_posts = require("./models/wo_posts")(sequelize, DataTypes)
  ctx.wo_comments = require("./models/wo_comments")(sequelize, DataTypes)
  ctx.wo_comment_replies = require("./models/wo_comment_replies")(sequelize, DataTypes)
  ctx.wo_pages = require("./models/wo_pages")(sequelize, DataTypes)
  ctx.wo_groups = require("./models/wo_groups")(sequelize, DataTypes)
  ctx.wo_events = require("./models/wo_events")(sequelize, DataTypes)
  ctx.wo_userstory = require("./models/wo_userstory")(sequelize, DataTypes)
  ctx.wo_reactions_types = require("./models/wo_reactions_types")(sequelize, DataTypes)
  ctx.wo_reactions = require("./models/wo_reactions")(sequelize, DataTypes)
  ctx.wo_blog_reaction = require("./models/wo_blog_reaction")(sequelize, DataTypes)
  ctx.wo_mute = require("./models/wo_mute")(sequelize, DataTypes)

  ctx.globalconfig = {}
  ctx.globallangs = {}
  ctx.socketIdUserHash = {}
  ctx.userHashUserId = {}
  ctx.userIdCount = {}
  ctx.userIdChatOpen = {}
  ctx.userIdSocket = []
  ctx.userIdExtra = {}
  ctx.userIdGroupChatOpen = {}

  await loadConfig(ctx)
  await loadLangs(ctx)

}


async function main() {
  await init()

  app.get('/', (req, res) => {
    res.sendFile(__dirname + '/index.html');
  });

  // Socket.io v4 initialization with secure CORS
  io = new Server(server, {
    cors: {
      origin: function (origin, callback) {
        // Allow requests with no origin (mobile apps, curl, etc.)
        if (!origin) return callback(null, true);

        // Check if origin is allowed
        if (ALLOWED_ORIGINS.some(allowed => origin.startsWith(allowed))) {
          callback(null, true);
        } else {
          console.warn('Blocked CORS request from:', origin);
          callback(new Error('Not allowed by CORS'));
        }
      },
      credentials: true,
      methods: ["GET", "POST"]
    },
    pingTimeout: 60000,
    pingInterval: 25000
  });

  // Redis adapter for socket.io v4 (if enabled)
  if (ctx.globalconfig["redis"] === "Y") {
    const { createAdapter } = require('@socket.io/redis-adapter');
    const { createClient } = require('redis');

    const pubClient = createClient({
      host: 'localhost',
      port: ctx.globalconfig["redis_port"] || 6379
    });
    const subClient = pubClient.duplicate();

    Promise.all([pubClient.connect(), subClient.connect()]).then(() => {
      io.adapter(createAdapter(pubClient, subClient));
      console.log('Redis adapter connected');
    }).catch(err => {
      console.error('Redis connection failed:', err);
    });
  }

  io.on('connection', async (socket) => {
    await listeners.registerListeners(socket, io, ctx)
  })

  server.listen(serverPort, function() {
    console.log('Bitchat server running on port %s', serverPort);
  });
}

main()
