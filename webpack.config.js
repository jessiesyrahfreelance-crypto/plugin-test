const path = require('path')
const TerserPlugin = require('terser-webpack-plugin')
const { CleanWebpackPlugin } = require('clean-webpack-plugin')
const MiniCssExtractPlugin = require('mini-css-extract-plugin')
const defaultConfig = require("@wordpress/scripts/config/webpack.config");

module.exports = {
  ...defaultConfig,

  entry: {
    'drivetestpage': './src/googledrive-page/main.jsx',
  },

  output: {
    path: path.resolve(__dirname, 'assets/js'),
    filename: '[name].min.js',
    publicPath: '../../',
    assetModuleFilename: 'images/[name][ext][query]',
  },

  resolve: {
    extensions: ['.js', '.jsx'],
  },

  externals: {
    react: 'React',
    'react-dom': 'ReactDOM',
    '@wordpress/element': 'wp.element',
    '@wordpress/components': 'wp.components',
    '@wordpress/api-fetch': 'wp.apiFetch',
    '@wordpress/i18n': 'wp.i18n',
  },

  module: {
    ...defaultConfig.module,
    rules: [
      ...defaultConfig.module.rules,
      {
        test: /\.(js|jsx)$/,
        exclude: /node_modules/,
        use: 'babel-loader',
      },
      {
        test: /\.(css|scss)$/,
        use: [
          MiniCssExtractPlugin.loader,
          'css-loader',
          'sass-loader',
        ],
      },
    ],
  },

  plugins: [
    ...defaultConfig.plugins,
    new CleanWebpackPlugin(),
    new MiniCssExtractPlugin({
      filename: '../css/[name].min.css',
    }),
  ],

  optimization: {
    minimize: true,
    minimizer: [
      new TerserPlugin({
        terserOptions: {
          format: { comments: false },
        },
        extractComments: false,
      }),
    ],
  },
}
