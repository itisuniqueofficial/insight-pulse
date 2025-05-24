# Use a lightweight PHP image
FROM php:8.2-cli

# Install curl (needed for Telegram API requests)
RUN apt-get update && apt-get install -y curl && apt-get clean

# Create app directory
WORKDIR /app

# Copy bot.php and any other necessary files
COPY bot.php ./

# Expose port for local debugging (not used in Koyeb)
EXPOSE 8080

# Set entrypoint
CMD ["php", "-S", "0.0.0.0:8080", "bot.php"]
