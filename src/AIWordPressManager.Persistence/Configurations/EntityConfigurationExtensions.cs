using AIWordPressManager.Domain.Common;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

internal static class EntityConfigurationExtensions
{
    public static void ConfigureEntity<TEntity>(this EntityTypeBuilder<TEntity> builder)
        where TEntity : Entity
    {
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).ValueGeneratedNever();
        builder.Property(x => x.CreatedAtUtc).IsRequired();
        builder.Property(x => x.UpdatedAtUtc).IsRequired();
        builder.Property(x => x.ConcurrencyToken).IsRequired().IsConcurrencyToken();
    }
}
